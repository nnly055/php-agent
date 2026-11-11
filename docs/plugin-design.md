# PHP Agent 插件架构设计

> 目标：把 provider、工具、**会话持久化**、斜杠命令、生命周期钩子全部变成可插拔的扩展点，
> 核心只提供"接口 + 注册容器 + 发现机制"。新增能力=写一个插件类，不改核心代码。

## 1. 现状盘点（哪些点值得插件化）

| 硬编码点 | 现状 | 插件化后 |
|---|---|---|
| Provider 选择 | `bin/agent`、`bin/server` 各有一段重复的 match | Provider 插件注册，`AGENT_PROVIDER` 只是个 id |
| 工具集 | 5 个工具在两个入口重复注册 | CoreTools 插件；新工具=新插件 |
| **会话持久化** | CLI 局部变量 / Server 内存数组，重启即丢 | `SessionStore` 接口 + 4 种实现，配置一行切换 |
| 斜杠命令 | `/help` 等写死在 switch | Command 插件（可拿 kernel 调 LLM，做 `/review` 这类） |
| 横切逻辑 | 无 | `AgentHook` 钩子：权限确认、审计、上下文裁剪 |

业界参照：Claude Code（hooks + MCP + slash commands）、opencode（TS 事件插件）、
Laravel/Symfony（service provider / bundle）。PHP 生态的惯例正是"容器注册 + 发现"，
本设计是其最小化版本。

## 2. 核心机制：Plugin 接口 + AgentKernel 注册容器

```php
interface Plugin
{
    public function name(): string;      // 'core-tools'
    public function version(): string;   // '1.0.0'
    public function register(AgentKernel $kernel): void;
}
```

`AgentKernel` 是注册容器兼服务定位器（刻意不做完整 DI，够用即可）：

```php
final class AgentKernel
{
    // ── 扩展点注册 ──────────────────────────────
    public function registerTool(Tool $tool): void;                    // 同名覆盖 + warning
    public function registerProvider(string $id, \Closure $factory): void; // 惰性工厂，后者覆盖
    public function setSessionStore(SessionStore $store): void;        // 后者覆盖
    public function addHook(AgentHook $hook): void;                    // 顺序执行
    public function registerCommand(string $name, Command $cmd): void;

    // ── 取用 ────────────────────────────────────
    public function provider(string $id): LLMClient;
    public function sessionStore(): SessionStore;
    public function toolRegistry(): ToolRegistry;
    public function hooks(): HookPipeline;
    public function config(): Config;
}
```

**覆盖语义**：加载顺序 = 内置插件 → composer 包插件 → 项目 `plugins/` 目录插件，
后注册者覆盖同 id 的 provider/store——用户插件因此能替换内置实现（这正是
"切换不同持久化方式"的实现机制：`AGENT_SESSION_STORE=sqlite3` 只决定内置
SessionPlugin 注册哪个实现；第三方插件也可以直接注册自己的 Store 覆盖）。

## 3. 扩展点接口设计

### 3.1 SessionStore（持久化——本设计的直接动机）

```php
interface SessionStore
{
    /** @return SessionMeta[] 按 updatedAt 倒序 */
    public function list(int $limit = 50): array;
    /** 读取归一化消息历史；不存在返回 [] */
    public function load(string $sessionId): array;
    /** 整段原子保存（每轮 agent 结束后调用） */
    public function save(string $sessionId, array $messages): void;
    public function delete(string $sessionId): void;
}

final class SessionMeta
{
    public string $id;
    public string $title;      // 取首条用户输入
    public int $createdAt;
    public int $updatedAt;
    public int $messageCount;
}
```

设计决策：

- **保存粒度=整段覆盖**。接口契约保持简单、天然原子（防半写）；
  SQLite 实现内部想优化成增量写入不影响接口。
- **存储内容=归一化消息数组**（就是 `AgentLoop` 维护的格式），JSON 序列化即可，
  带 `schemaVersion` 字段为将来消息格式演进留迁移空间。
- **保存时机**：由 Kernel 包装 `AgentLoop::run()`，每轮返回后 save——不侵入 AgentLoop。
- 内置实现（按本机 `php -m` 实测能力）：

| 实现 | 依赖 | 适用场景 |
|---|---|---|
| `InMemorySessionStore` | 无 | 默认，现行为不变 |
| `JsonFileSessionStore` | 无 | **默认持久化**：`~/.php-agent/sessions/<id>.json`，零依赖、人可读、易 diff |
| `Sqlite3SessionStore` | ext-sqlite3 ✅ | 会话多了之后查询/清理快，单文件 |
| `RedisSessionStore` | ext-redis ✅ | server 多进程/桌面壳常驻场景 |

  选择用 `AGENT_SESSION_STORE=memory|json|sqlite3|redis` 控制（内置 SessionPlugin 读取）。

### 3.2 AgentHook（横切能力——权限、审计、上下文工程的基础）

```php
interface AgentHook
{
    /** @return string[] 关注的事件，[] = 全部 */
    public function events(): array;
    public function handle(string $event, array $payload): void;
}

final class HookVetoException extends \RuntimeException {} // before_* 事件抛出即可否决
```

| 事件 | payload | 可否决 | 典型用途 |
|---|---|---|---|
| `before_tool_call` | tool, input | ✅ 抛 HookVetoException | **工具执行前确认（Y/n）**、危险命令拦截 |
| `after_tool_result` | tool, input, result | — | 审计日志、敏感信息脱敏 |
| `before_llm_request` | messages（引用） | — | 上下文裁剪/注入（RAG、历史压缩） |
| `after_turn` | messages, stopReason | — | token 统计、telemetry |
| `session_saved` | sessionId | — | 云端同步 |

对 `AgentLoop` 的侵入：仅构造函数加可选 `?iterable $hooks = null` + 4 个触发点，
不传时行为与现在完全一致——现有测试零改动。

### 3.3 Command（斜杠命令）

```php
interface Command
{
    public function description(): string;
    /** @return string 展示给用户的文本 */
    public function execute(array $args, AgentKernel $kernel): string;
}
```

内置 `/help /reset /tools /exit` 迁为插件注册。插件可加 `/review`（拿 kernel 里的
client 调 LLM 审 diff）、`/commit` 等。

### 3.4 Provider

`LLMClient` 接口不动。内置拆成 `AnthropicPlugin` / `OpenAICompatPlugin` / `FakePlugin`，
各自 `registerProvider('anthropic', fn () => new AnthropicClient($config))`。
以后给某家模型做专属适配（reasoning 字段差异等）= 新增一个 provider 插件，不动核心。

## 4. 发现与加载（三档，渐进实现）

1. **内置**：`src/Plugins/*`，KernelBuilder 里的固定清单。
2. **项目插件**：`plugins/<name>/plugin.php` 返回插件类名，PSR-4 映射
   `plugins/<name>/src` → `PhpAgent\Plugin\<Name>\`。用户热改、随项目走。
3. **Composer 包**（Phase 3）：第三方包在 composer.json 里声明
   `"extra": { "php-agent": { "plugins": ["Vendor\\Plugin\\Class"] } }`，
   读 `vendor/composer/installed.json` 自动发现——生态分发用。

配置开关：`AGENT_PLUGINS=off`（只加载内置）、或显式列表。

## 5. 重构后的目录结构

```
src/
├── Kernel/
│   ├── AgentKernel.php            # 注册容器
│   ├── Plugin.php / PluginManager.php
│   └── HookVetoException.php
├── Session/
│   ├── SessionStore.php / SessionMeta.php
│   └── Store/{InMemory,JsonFile,Sqlite3,Redis}SessionStore.php
├── Hook/                          # AgentHook 接口 + 内置示例（AuditLogHook）
├── Command/                       # Command 接口 + 内置命令
├── Plugins/                       # 内置插件：CoreTools / Providers / Session / Commands
└── Agent|LLM|Tools|Ui|Server/     # 现有代码基本不动

plugins/                           # 用户项目插件目录
└── example/
    ├── plugin.php                 # <?php return ExamplePlugin::class;
    └── src/ExamplePlugin.php

bin/agent, bin/server              # 收敛为 ~15 行：
                                   # $kernel = AgentKernelBuilder::build($config);
                                   # PluginManager::load($kernel);
                                   # 启动 UI / Server
```

## 6. 分阶段落地

### Phase 1：会话持久化插件化（直接回应需求）

- Kernel / Plugin / PluginManager 骨架
- SessionStore 四实现 + 归一化消息 JSON 序列化（schemaVersion）
- 内置插件化：CoreToolsPlugin、三个 ProviderPlugin、SessionPlugin、CommandsPlugin
- CLI：每轮自动保存、`/sessions` 列表、`/resume [id]` 恢复、`/new` 开新会话
- Server：`AgentServer` 的内存数组换成 SessionStore，重启不丢会话
- **验收标准**：
  - CLI 退出重进 `/resume` 继续上次对话；
  - server 重启后网页会话仍在；
  - `AGENT_SESSION_STORE=sqlite3|json|memory|redis` 一行切换持久化后端；
  - 现有 6 个单测不变全过 + 新增 store 往返测试。

### Phase 2：钩子系统

- AgentHook + 5 个触发点接入 AgentLoop
- 内置 ConfirmHook：`before_tool_call` 时 CLI 交互确认（对标 Claude Code 权限模式）
- 内置 AuditLogHook：工具调用审计写 JSONL

### Phase 3：生态

- composer extra 发现机制
- 插件脚手架（`php bin/agent --plugin:new <name>` 生成骨架）
- 插件文档 + 示例（天气查询工具、git 命令包等）

## 7. 风险与取舍

- **接口即承诺**：SessionStore/Hook 发 v1 后尽量窄接口、先窄后宽，避免破坏第三方插件。
- **序列化演进**：消息结构若重构，靠 `schemaVersion` 做迁移；归一化格式与
  `LLMClient` 的映射已经隔离（客户端负责转换），风险可控。
- **打包依赖**：static-php / FrankenPHP 打包时需保留 `sqlite3`、`redis` 扩展。
- **插件信任**：插件可执行任意代码。Phase 3 前只支持本地/显式配置加载；
  第三方分发时再考虑权限声明与签名。
