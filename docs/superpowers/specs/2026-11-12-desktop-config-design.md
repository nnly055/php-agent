# 桌面端 API 配置功能设计（全局 provider / key / baseUrl / model）

日期：2026-11-12
状态：已确认（用户批准）
关联文档：`docs/desktop-shell-design.md`、`docs/superpowers/specs/2026-11-11-desktop-shell-design.md`

## 背景与问题

桌面端标题栏显示 `openai/未配置`（`TitleBar.tsx` 的 `provider/model` 徽章），
但没有任何配置入口：

- 配置唯一来源是 `AGENT_*` 环境变量 + 后端根目录 `.env`（`Config::load`，server 模式 shell 权威）
- 打包分发时 `.env` 位于 .app bundle 内**只读**；壳启动 sidecar 只注入
  端口/工作目录/权限/会话目录，不注入 provider/key/model
- 结果：打包版桌面端永远「未配置」，用户无法使用真实 LLM

## 目标与本轮范围

在桌面端提供**完整的 API 配置功能**：

- 设置对话框：provider（openai 兼容 / anthropic / fake）、API Key、Base URL、Model
- 「测试连接」：用表单当前值发一次最小真实请求，显示成功/失败与延迟
- 保存后全局生效：写入 `~/.php-agent/desktop.json`，重启所有运行中的 sidecar
- 标题栏徽章成为配置入口；key/model 缺失时橙色警示
- 空项目页提供「配置 API」入口（首次使用路径）

本轮**不做**（明确排除）：

- 每项目独立的 provider/key 覆盖（全局一份，机器级心智；后续需要再迭代）
- 运行时热更新（改配置必须重启 sidecar；会话已持久化可恢复，与现有
  `restart_session` 语义一致）
- 系统级密钥链（keychain/凭据管理器）集成

## 关键决策记录

| 决策点 | 选择 | 理由 |
|---|---|---|
| 作用域 | 全局统一一份 | API key 是机器级心智；desktop.json 已有持久化机制；每项目覆盖 UI 复杂度翻倍 |
| 生效方式 | 保存后重启 sidecar | 复用「shell 权威」语义与 `restart_session`；热更新需打通 kernel 多层装配，风险不值 |
| 持久化 | `~/.php-agent/desktop.json` 顶层 `config` 字段 | 单文件管理桌面壳全部持久化；serde default 向后兼容旧格式 |
| 注入方式 | `start_with_env` 注入 `AGENT_*`，空值不注入 | server 模式 shellWins=true，注入即权威，覆盖 bundle 内只读 .env；不注入则保持 .env/CLI 行为 |
| 测试连接 | PHP 端 `POST /config/test` | 复用现成 LLM client（`OpenAICompatClient`/`AnthropicClient`），前端不用自己实现两套协议 |
| key 存储 | 明文存 desktop.json，文件权限 0600 | 单用户本地桌面应用惯例；`/status` 不回传 key；前端密码框显示 |
| 测试连接实现 | 直接 `new OpenAICompatClient($tmpConfig)` 等临时实例 | 插件注册的工厂本就是 `new XClient($config)`；不引入 kernel 依赖，AgentServer 构造不变 |

## 详细设计

### 1. PHP 端

**`Config::fromArray(array $env): self`** 静态工厂（新增）：

- 构造函数私有，`/config/test` 与单测需要用临时 env 构造实例
- 接受完整 `AGENT_*` 键值映射，语义等同 `load()` 合并后的结果

**`POST /config/test`**（`AgentServer` 新增路由）：

- 请求体：`{provider, api_key, base_url, model}`（均为 string，可为空串）
- 处理：
  1. provider 合法性校验（`openai|anthropic|fake`），非法返回 400
  2. model 为空且 provider ≠ fake → 不发请求，直接 `{ok:false, message:"未配置模型"}`
  3. 用 `Config::fromArray` 构造临时 Config，固定 `AGENT_MAX_TOKENS=16`（控制测试成本）
  4. fake → 直接返回 ok；openai/anthropic → 实例化对应 client，发一次
     `stream([['role'=>'user','content'=>'ping']], [], '')` 并消费到流结束
     （ping 体量秒级完成；中途放弃 generator 会触发 finally 拉流，语义等价但更绕）
  5. 捕获 `LLMException`/`Throwable` → `{ok:false, message}`
- 响应：`{ok: bool, message: string, latency_ms: int}`
- **不写审计日志、不落任何 key 日志**（路由在审计中间件之外即可；当前 server 本就不审计 HTTP 请求体）
- 空字段回退语义与 `Config` 一致（base_url 空 = provider 默认端点）

### 2. Rust 壳（desktop/src-tauri）

**持久化**：`PersistedState` 增加 `#[serde(default)] config: Option<SidecarConfig>`；旧文件无该字段 → None（保持现行为）。

```rust
#[derive(serde::Serialize, serde::Deserialize, Clone)]
struct SidecarConfig {
    provider: String,   // openai | anthropic | fake
    api_key: String,
    base_url: String,   // 空 = provider 默认
    model: String,
}
```

**新命令**：

- `get_config() -> SidecarConfig`：读 desktop.json；无配置返回全空默认值
- `save_config(provider, api_key, base_url, model) -> ()`：校验 provider 合法 → 写回
  desktop.json（`create_dir_all` 父目录 + 文件权限 **0600**（unix））→ 已运行的
  sidecar 不在此命令内重启（由前端逐 tab 调 `restart_session`，职责单一）

**`restart_session` 签名变更**：`-> Result<OpenedSession, String>`（现为 `Result<(), String>`）。
原因：`open_session_inner` 重启后生成**新的 sessionId** 与端口，当前返回 void 导致
前端 tab 绑定失效。返回 `{sessionId, port, workspace}` 供前端重绑。

**`Sidecar::start_with_env`** 签名追加参数 `config: Option<&SidecarConfig>`：

- Some 且字段非空 → 逐项 `cmd.env("AGENT_PROVIDER"/"AGENT_API_KEY"/"AGENT_BASE_URL"/"AGENT_MODEL", v)`
- None 或空值 → 不注入（现有单测传 None，行为不变；bundle 内 .env 与系统 env 继续兜底）
- `open_session_inner` 从 `AppState` 读持久化 config 传入

### 3. 前端（desktop/src）

**`lib/tauri.ts`**：新增 `getConfig()` / `saveConfig(...)` 包装；`restartSession` 返回类型改为 `OpenedSession`。

**`components/settings/SettingsDialog.tsx`**（新增，Radix Dialog）：

- 字段：
  - Provider 下拉：`openai`（显示「OpenAI 兼容（OpenAI/GLM/DeepSeek/Kimi…）」）/ `anthropic` / `fake`
  - Base URL：placeholder 按 provider 显示默认端点（openai: `https://api.openai.com/v1`，anthropic: `https://api.anthropic.com`）
  - API Key：`type=password` 输入框
  - Model：文本输入（placeholder 示例：`gpt-4o` / `glm-4.7` / `claude-sonnet-4-5`…）
- 「测试连接」按钮：POST 当前 sidecar `/config/test`（表单当前值），行内显示
  `✓ 320ms` 或错误 message；仅当存在已连接 tab 时可用（测试走该 tab 的 baseUrl）
- 「保存」：`saveConfig` → 成功后对每个运行中的 tab 依次
  `restartSession(tab.id)` → 用返回值**重绑 tab**（新 id、新 store sessionId/backendUrl）→
  各 tab 重新拉 `/status` → 关闭对话框
- 重启失败的 tab：`setError` 并保持 connected=false（ConnectionBanner 已有重试 UI）
- 保存中禁用按钮；测试与保存互斥（同一时间只跑一个请求）

**入口**：

- `TitleBar` 徽章可点击打开设置；`!model` 时徽章加 `text-warning` 橙色 +
  Settings 图标提示可配置
- `EmptyProjects` 空态新增「配置 API」次级按钮（无项目时也能先配好 key）
- 对话框状态提升到 `App.tsx`（`settingsOpen` state + context/prop 下发），
  因为 TitleBar 与 EmptyProjects 分属不同挂载点

**数据流总览**：

```
打开对话框 ──getConfig──▶ 表单回填
测试连接 ──POST /config/test──▶ 行内结果
保存 ──save_config──▶ desktop.json(0600)
     ──restartSession(tab)×N──▶ 新 {sessionId, port}
     ──▶ 重绑 tab + getStatus 刷新 ──▶ 徽章变为 provider/model
```

### 4. 安全

- key 明文存 `~/.php-agent/desktop.json`，文件权限 0600（unix；Windows 用户目录默认 ACL）
- `/status` 与 `/config/test` 响应均不回传 key；测试请求体不进审计/日志
- 前端 key 输入框 `type=password`

## 错误处理

| 场景 | 行为 |
|---|---|
| provider 非法 | save_config 与 /config/test 均 400/报错，表单标红 |
| 测试连接网络失败/401 | 行内显示 server 返回的 message（含 HTTP 状态语义） |
| 重启某 tab 失败 | 该 tab connected=false + error，其他 tab 不受影响 |
| desktop.json 损坏 | 沿用现状 `unwrap_or_default()` → 视为未配置 |
| 旧版 desktop.json（无 config 字段） | serde default → 全空默认，行为与今天一致 |

## 测试计划

- **PHP**（phpunit，沿用现有测试风格）：
  - `Config::fromArray` 各字段读取 + 空值回退
  - `/config/test`：fake → ok；非法 provider → 400；缺 model（openai）→ 明确错误信息
- **Rust**（cargo test）：
  - desktop.json config 字段序列化往返 + 旧格式（无 config）兼容
  - `start_with_env` 注入矩阵：Some/None、部分空字段不注入（现有测试改为传 None 适配）
- **前端**（vitest + testing-library）：
  - SettingsDialog：表单回填、测试连接成功/失败展示、保存触发 restart 序列
  - TitleBar：未配置橙色徽章渲染、点击打开对话框
- **端到端手工验收**：`npm run tauri dev` 配置 fake → 徽章变 `fake/<model>`；
  配置真实 openai 兼容端点 → 测试连接 ✓ → 发消息得到真实回复

## 验收标准

1. 全新安装（无 .env、无 desktop.json）→ 打开设置 → 配置 GLM/OpenAI 兼容端点 →
   测试连接成功 → 保存 → 标题栏显示 `openai/<model>` → 对话可用
2. 修改配置保存后，所有已打开项目 tab 自动重启并保持会话历史可恢复
3. 不配置时标题栏橙色提示「未配置」，点击直达设置
4. key 不出现在 /status 响应、日志与任何 UI 明文中
