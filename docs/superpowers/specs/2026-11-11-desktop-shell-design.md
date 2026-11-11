# 桌面壳设计（MVP：Tauri 2 + React 前端 + PHP sidecar）

日期：2026-11-11
状态：已确认（用户批准）
关联路线图：README「桌面壳：Tauri sidecar 加载 PHP 内核」

## 背景与目标

php-agent 已有 CLI（`bin/agent`）与本地 SSE 服务（`bin/server`，ReactPHP）。
server 目前只有 `POST /agent` 一个对话接口 + 一个 191 行极简网页聊天页，
缺少桌面应用所需的会话管理、授权确认、端口协商等对接面。本轮把「桌面壳」
路线图项落地为一个**端到端可用的桌面 MVP**。

本轮范围（与用户确认）：

- **Tauri 2 壳**：管理 PHP sidecar 子进程生命周期、端口发现、workspace 选择、单实例
- **独立 React 前端**（`desktop/`，Vite + shadcn/ui + Tailwind）：会话侧栏、流式聊天、
  工具调用卡片、授权弹窗、diff 上色
- **补齐后端 HTTP API**：会话列表/读取/删除、授权确认流、`/status`、端口协商、CORS
- **本机 dev 模式跑通完整桌面体验**（`npm run tauri dev`）

本轮**不做**（留下一轮）：

- 正式打包分发：static-php / FrankenPHP 单二进制、macOS `.app`/`.dmg`、代码签名
- 服务端会话锁 / 多会话并发流（MVP 假定每会话同一时刻只有一条流）
- 斜杠命令的桌面分发（桌面用 UI 按钮替代：新建会话、压缩等）
- MCP resources / prompts / sampling（沿用现状，仅 tools）

## 关键决策记录

| 决策点 | 选择 | 理由 |
|---|---|---|
| 本轮范围 | 端到端 MVP，打包延后 | 先跑通完整体验，打包是独立子项目 |
| 壳技术 | Tauri 2 | 路线图既定；本机已装 Rust 1.97；体积小 |
| 前端 | 独立 React 前端（`desktop/`） | 用户选择；组件化开发体验 |
| 组件库 | shadcn/ui + Tailwind | 代码拷入仓库完全掌控，契合「零框架」理念；聊天主体是定制面，库只供外壳 |
| workspace | 文件夹 dialog 选择，重启 sidecar 切换 | 桌面应用基本体验；重启即换上下文，成本可控 |
| 集成拓扑 | 方案 A：sidecar 子进程，前端直连 HTTP/SSE | Rust 代码最少、复用现有接口、打包轮架构不动 |
| 状态管理 | Zustand 单一 store | 避免多套状态打架；流式高频更新靠选择性订阅 |

被否方案：B（所有流量经 Tauri IPC 代理，需在 IPC 上重造流式、代码翻倍）；
C（FrankenPHP 进程内嵌入，构建复杂，留作打包轮候选）。

## 总体架构与进程模型

```
┌────────────────────── Tauri 2 桌面应用 ──────────────────────┐
│ Rust 壳 (desktop/src-tauri)                                  │
│  · spawn/监控/重启 PHP sidecar    · 文件夹 dialog（workspace）│
│  · 端口发现 + 健康检查            · 退出杀子进程、单实例      │
│ React 前端 (desktop/，Vite + shadcn/ui)                      │
│  · 会话侧栏 · 聊天流式渲染 · 授权弹窗 · diff 上色            │
└──────────────────────────┬───────────────────────────────────┘
                           │ HTTP + SSE（127.0.0.1:<动态端口>）
┌──────────────────────────▼───────────────────────────────────┐
│ php bin/server（sidecar；dev = 系统 PHP，打包轮换静态二进制） │
│  · 现有 POST /agent SSE   · 新增 sessions/confirm/status API │
│  · ask 模式 ConfirmHook：HTTP 确认挂起 Fiber                 │
└──────────────────────────────────────────────────────────────┘
```

**启动时序**：

1. 壳 spawn `php bin/server`，注入 env：`AGENT_SERVER_PORT=0`（自动选端口）、
   `AGENT_WORKSPACE=<上次或默认>`、`AGENT_PERMISSIONS=ask`、`AGENT_UI=server`；cwd = 项目根
2. server 绑 `127.0.0.1:0`，listen 后从 `$socket->getAddress()` 读实际端口，
   向 stdout 打机器可读行 `AGENT_LISTENING port=<port>`（人类可读行保留）
3. 壳逐行读 stdout 解析端口 → 有限次轮询 `GET /status` 就绪 → 发 Tauri event `backend://ready`
4. 前端经 Tauri command `get_backend_url` 拿到 `http://127.0.0.1:<port>` → 拉 `/status`+`/sessions` → 渲染

**workspace 切换**：文件夹 dialog 选中 → 壳校验目录存在 → 杀旧 sidecar →
以新 `AGENT_WORKSPACE` 重启 → 前端重置会话视图。上次选择持久化在 `~/.php-agent/desktop.json`。

**进程监管**：sidecar 意外退出 → 壳检测 → Tauri event `backend://disconnected` →
前端「后端已断开」横幅 + 重连按钮 → 壳按原配置 respawn。应用退出（含关窗）杀子进程**进程组**，
处理 SIGTERM/SIGINT，杜绝孤儿 PHP。`tauri-plugin-single-instance` 防多开（聚焦已有窗口）。

**开发模式**：`npm run tauri dev` 一条命令起 Vite dev server（端口 1420，strictPort）+ 壳 + sidecar。
前端由 Vite/Tauri 伺服（不再走 PHP `GET /`）；PHP 只提供 API。旧网页聊天页 `public/index.html` 保留不动。

**CORS 推论**：前端 origin（dev `http://localhost:1420`，打包后 `tauri://localhost`）与 API 不同源，
server 必须加 CORS 头 + 处理 OPTIONS 预检。仅绑 127.0.0.1，`Access-Control-Allow-Origin: *` 安全。

## 后端 API（ReactPHP `AgentServer`）

`SessionStore` 契约已有 `list/load/save/delete`，四个存储均实现——会话 API 只是薄 HTTP 包装，**不动存储层**。

### 端点契约

| 端点 | 请求 | 响应 |
|---|---|---|
| `GET /status` | — | `{provider, model, workspace, permissions, sessionStore, mcp, version, maxIterations}` |
| `GET /sessions?limit=N` | limit 默认 50 | `{sessions: [SessionMeta.toArray(), …]}`（updatedAt 倒序） |
| `GET /sessions/{id}` | — | `{id, messages: [归一化历史]}`；缺失 → `messages: []` |
| `DELETE /sessions/{id}` | — | `{ok: true}`（幂等） |
| `POST /confirm/{id}` | `{decision: "y"\|"n"\|"a"}` | `{ok: true}`；未知/过期 id → 404 `{error}` |
| `POST /agent` | `{message, session_id}`（现有） | SSE 事件流，**新增 `confirm_request` 事件** |
| `OPTIONS *` | 预检 | 204 + CORS 头 |

- `/status` 字段来源：`provider/model/workspace/permissions/maxIterations` 取自 `Config`；
  `sessionStore` = `Config::sessionStore()`（id 字符串 json/memory/sqlite3/redis）；`mcp` = `Config::mcpEnabled()`；
  `version` = **新增 `Config::VERSION` 常量**（初始 `0.2.0`，发版手动 bump，单一来源——代码现无版本常量）。
- 新会话无需 create 端点：前端生成新 `session_id` 直接 POST `/agent`。
- 所有响应带 CORS 头：`Access-Control-Allow-Origin: *`、
  `Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS`、`Access-Control-Allow-Headers: Content-Type`。
- 坏 JSON body → 400 `{error}`；错误响应同样带 CORS 头。
- `session_id` 非空校验；存储层已对文件名做白名单净化。

### 端口协商

- `Config::serverPort()` 现 `max(1, …)` 会把 0 钳成 1 → 改为放行 0（`max(0, …)`，0 = 自动）。
- `bin/server`：`new SocketServer('127.0.0.1:' . $port)`；`$port === 0` 时 listen 后
  解析 `$socket->getAddress()`（形如 `tcp://127.0.0.1:54321`）取实际端口，
  `echo "AGENT_LISTENING port={$actual}\n"`。固定端口配置则沿用原值。

### 授权确认流（核心新机制）

复用现有 `ConfirmHook`（`before_tool_call` 钩子，prompt 闭包返回 `y|n|a`，
只读工具与 `ReadOnlyTool` 自动放行，`a` 本进程总是允许）。把 prompt 闭包换成 HTTP 版：

- **新增 `src/Server/ConfirmBroker.php`**——单一职责承载 HTTP 确认协调，可脱离 socket 单测：
  - `setEmitter(\Closure $emit): void`：每请求开始时由 `AgentServer` 注入当前 SSE 发射器
  - `request(string $tool, array $input): string`：作为 ConfirmHook 的 prompt——
    生成 confirm id → 组装 preview → 经 emitter 发 `confirm_request` 事件 →
    建 `React\Promise\Deferred` 存入 pending 表 + 挂 120s 超时定时器（超时 resolve `n`）→
    `return React\Async\await($deferred->promise())`
  - `resolve(string $id, string $decision): bool`：`POST /confirm` 命中 pending → resolve + 清表 + 取消定时器，返 true；未知返 false
  - `discardActive(): void`：SSE 流关闭时把当前 pending resolve `n`，防泄漏
- **挂起不阻塞**：agent 循环跑在 `React\Async\async`（Fiber 协程）内，`await` 挂起协程但
  事件循环继续运转，`/confirm` 等其它请求照常处理。
- **单流假定**：MVP 每会话同一时刻只有一条流，故 broker 同一时刻至多一个 pending confirm，
  `activeEmit`/`discardActive` 无歧义。前端流式期间禁用 Composer 保证此假定。
- **preview 组装**（broker 内，best-effort）：
  - `write_file`：`class_exists(DiffRenderer::class)` 时读原文件 + `DiffRenderer::render($old,$new,40)` 算 unified diff 并剥 ANSI；
    新文件给「新文件 · N 行 · M bytes」摘要；原文件超 `DiffRenderer::MAX_DIFF_BYTES`（512KB）跳过预览；
    diff 插件停用则降级为参数展示（preview 空）
  - `run_command`：preview = 命令串
  - 其它：preview 空，前端展示 input JSON
- **`StreamEvent::confirmRequest(string $id, string $tool, array $input, string $preview): self`**
  → type `confirm_request`，data `{id, tool, input, preview}`。
- **装配**：`bin/server` 在 `permissions=ask` 时 `new ConfirmHook($prompt, $kernel->toolRegistry())`
  并加入 hooks（`$prompt = fn($t,$i) => $broker->request($t,$i)`）；`permissions=off` 不装。
  ConfirmHook 在 server 进程内共享，`a` 的总是允许记忆跨请求保留。

### DiffPreviewHook 的 server 适配

- 现状：`DiffPreviewHook` 往 stdout echo ANSI 预览（CLI 用）。server 模式下抑制，避免污染 sidecar stdout。
- 机制：`DiffPreviewHook::__construct(string $workspace, ?\Closure $output = null)`，
  null = 默认 echo（CLI 不变）；`DiffPlugin` 读 `getenv('AGENT_UI') === 'server'` 时传 no-op 输出回调。
  `bin/server` 启动即 `putenv('AGENT_UI=server')`。
- 桌面授权预览改由 ConfirmBroker 用 DiffRenderer 计算并随 `confirm_request` 下发（见上），与该钩子解耦。

## 前端（`desktop/`，Vite + React + shadcn/ui）

### 技术栈

- Vite + React 18 + TypeScript；Tailwind + shadcn/ui（Radix 原语）；`@tauri-apps/api`
- 状态：Zustand 单一 store（`backendUrl / status / sessions / activeSession / messages / streaming / pendingConfirm`）
- 依赖克制：`react-markdown` + `remark-gfm`（回复渲染）、`lucide-react`（图标）、`zustand`；
  diff 自行按行解析上色，不引重型 diff 库

### 目录结构

```
desktop/
  package.json · vite.config.ts · tsconfig.json · index.html
  src/
    main.tsx · App.tsx · index.css(Tailwind + 主题 token)
    lib/      api.ts · sse.ts · tauri.ts · utils.ts(cn/ANSI 剥离/diff 解析)
    store/    useAppStore.ts
    hooks/    useAgentStream.ts
    components/
      layout/   AppShell · TitleBar · Sidebar · StatusBar
      chat/     ChatView · MessageBubble · ToolCallCard · DiffView · Markdown · Composer
      confirm/  ConfirmDialog
      sessions/ SessionList · SessionItem
      ui/       shadcn 原语（button/dialog/scroll-area/tooltip/dropdown-menu/badge…）
  src-tauri/
    Cargo.toml · tauri.conf.json · build.rs · capabilities/
    src/      main.rs · lib.rs（sidecar 管理/端口解析/dialog/单实例）
```

### 数据流

`useAgentStream` 把 `POST /agent` 的 SSE 解析成事件异步迭代器，逐条写 store：
`text_delta`/`thinking_delta` 追加当前 assistant 消息；`tool_use_start/end`、`tool_result`
维护工具卡片；`confirm_request` 设 `pendingConfirm` 弹授权 Dialog；`final` 收尾；`error` 错误气泡。
授权决定经 `POST /confirm/{id}` 回传。切会话 = `GET /sessions/{id}` 渲染只读 transcript，
并用 `AbortController` 中止在途流防跨会话串数据。

### Tauri 接口

- commands：`get_backend_url() -> String`、`pick_workspace() -> Option<String>`（文件夹 dialog）、
  `restart_backend(workspace: String)`、`get_workspace() -> String`
- events：`backend://ready {port}`、`backend://disconnected`、`backend://error {message}`
- sidecar 管理用 `std::process::Command`（dev 直接 spawn 系统 `php`；打包轮换捆绑二进制），
  stdout 管道 + 读线程解析端口行，child 存 state，Drop 时杀进程组
- `tauri.conf.json`：productName「PHP Agent」、devUrl `http://localhost:1420`、frontendDist `../dist`、
  窗口 1200×800（min 900×600）、暗色主题

### 视觉方向（一眼是「PHP 的开发者工具」）

从 PHP 本体身份取色——PHP 象/标志的**靛紫**作主色族，落在带靛蓝底调的**近黑暖中性**背景上，
配终端霓虹强调色。设计 token（CSS 变量，Tailwind 消费）：

| 角色 | token | 值 |
|---|---|---|
| 背景 | `--bg` | `#0B0D12` |
| 表面 | `--surface` | `#141821` |
| 浮层 | `--elevated` | `#1A1F2B` |
| 主色（PHP 靛紫） | `--primary` / `--primary-deep` | `#8B8DF6` / `#5B5BD6` |
| 流式/激活 电青 | `--info` | `#38BDF8` |
| 成功/diff+ 酸绿 | `--success` | `#4ADE80` |
| 运行/警告 琥珀 | `--warning` | `#FBBF24` |
| 错误/diff−/拒绝 珊瑚红 | `--danger` | `#FB7185` |
| 思维链 品红 | `--thinking` | `#E879F9` |
| 文本 / 弱化 | `--text` / `--muted` | `#E8EAF2` / `#8A90A6` |

- **字体**：UI 用 Geist/Inter grotesk；代码/工具参数/diff 用 JetBrains Mono；字标略具性格
- **布局**：左会话侧栏（顶部 workspace 选择器 + 搜索 + 新建）｜主区聊天流 + 底部 Composer｜
  顶部细标题栏（字标 · workspace 路径 · provider/model 徽章 · 权限模式 · 连接状态）｜底部 StatusBar
- **动效与细节**：流式光标闪烁；工具卡按状态色左边框（运行琥珀/成功绿/拒绝红）+ 展开折叠；
  diff 带行号按行上色；授权 Dialog 键盘 Y/N/A/Esc；会话项 hover 反馈；入场分级动画；
  **滚动条按本调色板自绘**（不用浏览器默认）
- **空态**：有辨识度的欢迎屏（PHP 象 motif + 快速开始建议按钮），不是白板

## 错误处理与边界

**壳 / sidecar**：spawn 失败 → 窗口错误态 + 重试 + 日志；崩溃 → 横幅 + 重连 + respawn；
退出杀进程组；健康检查有限次轮询超时进错误态；重启前校验文件夹存在。

**后端**：确认超时 120s → `n` → 现有 `HookVetoException` → 工具 `DENIED`、agent 继续；
SSE 断开 → `discardActive` 清 pending；provider 错误 → `error` 事件、**失败不保存会话**（沿用现状）；
缺失会话 `load` 返 `[]`；`DELETE` 幂等；坏 JSON → 400；未知 confirm id → 404；
`permissions=off` 无 confirm 事件；审计（AuditPlugin）对 server 照常生效。

**前端**：网络/SSE 错误 → 错误气泡 + 重连；坏帧 try/catch 跳过；`get_backend_url` 未就绪 → loading；
切会话 AbortController 中止在途流；授权 Dialog 开着时流结束/出错 → 关弹窗按拒绝；
react-markdown 默认无 `dangerouslySetInnerHTML`（防注入）；大工具输出截断 + 可展开。

## 测试策略与验收标准

沿用 PHPUnit 为主（现 101 个测试须保持绿）。

**后端 PHPUnit（自动化主体）**：
- CORS 头 / OPTIONS 204；`/status` 字段；会话 list/load/delete（临时 JSON 目录或 InMemory）
- `Config::serverPort()` 放行 0；`StreamEvent::confirmRequest` 形状
- **确认流重点**：`FakeLLMClient` 触发 `write_file`/`run_command`，对 `ConfirmBroker` 单测
  `y` 执行 / `n` 拒绝 / `a` 总是允许 / 超时→n / 未知 id；`discardActive` 清理
- 回归：CLI 双参 prompt 闭包仍可用；DiffPreviewHook CLI echo 不变

**Rust**：纯函数单测（解析 `AGENT_LISTENING port=NNN`、workspace env 构造）；spawn/kill 走集成验证。

**前端 Vitest + RTL**：SSE 解析（分帧/半帧/事件分发）、diff 按行上色、store actions、
ConfirmDialog 键盘 Y/N/A/Esc、ToolCallCard 状态。

**端到端验收（MVP 完成定义）**：
- `npm run tauri dev` 起窗；fake 离线 + 真实 provider 均通；逐 token 流式
- write_file 弹授权含 diff 预览（Y 执行 + 结果 diff / N 拒绝 / A 总是允许）
- 会话 新建/恢复/删除；文件夹切换重启 sidecar 且沙箱跟随
- 杀 sidecar → 横幅 → 重连；退出无孤儿 PHP 进程

## 新增 / 修改文件清单

**后端（修改 + 新增）**：
```
src/Server/ConfirmBroker.php        # 新增：HTTP 确认协调（pending/Deferred/超时/preview）
src/Server/AgentServer.php          # 路由 + CORS + /status + sessions + confirm + 接 broker
src/LLM/StreamEvent.php             # confirmRequest 工厂
src/Config.php                      # serverPort 放行 0 + VERSION 常量
bin/server                          # 端口 0 + AGENT_LISTENING 行 + ConfirmHook 装配 + AGENT_UI=server
plugins/diff/src/DiffPreviewHook.php# 可选 output 回调
plugins/diff/src/DiffPlugin.php     # 按 AGENT_UI 选 echo/no-op
tests/ServerApiTest.php             # 新增：端点 + CORS
tests/ConfirmBrokerTest.php         # 新增：确认流
```

**壳 + 前端（新增 `desktop/` 整树）**：见上方目录结构。

**文档/配置**：`README.md`（路线图勾选 + 桌面章节）、`.env.example`（`AGENT_SERVER_PORT=0`、`AGENT_UI` 说明）。

## 风险与后续

- **打包轮**（独立子项目）：static-php-cli 或 FrankenPHP 把 PHP + 扩展（curl/json/mbstring/sqlite3/redis）
  打成单二进制作 sidecar；Tauri bundler 出 `.app`/`.dmg`；架构不变，仅把「系统 php」换「捆绑二进制」。
- **并发**：若未来需多会话并发流，需引入服务端会话锁 + broker 多 pending 上下文（本轮单流假定）。
- **Rust 侧复杂度**：sidecar 监管/进程组清理是易错点，靠集成验收兜底；纯逻辑尽量抽纯函数单测。