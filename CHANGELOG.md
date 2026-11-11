# 更新日志

本项目所有显著变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### 新增

- **插件架构 Phase 2：钩子系统 + 权限确认 + 审计日志**
  - `AgentLoop` 接入 4 个钩子点（构造参数可选，默认零行为变化）：
    `before_llm_request`（引用携带 messages，可做上下文裁剪/注入；否决则中止本轮）、
    `before_tool_call`（抛 `HookVetoException` 否决，结果以 `DENIED:` 回传模型）、
    `after_tool_result`（审计/脱敏）、`after_turn`（统计/telemetry）；
    `session_saved` 由 CLI/server 保存会话后触发
  - 内置 `ConfirmHook`：工具执行授权（只读工具自动放行，Y/n/a=本次会话总是允许，
    非交互终端自动拒绝），CLI 在 `AGENT_PERMISSIONS=ask`（默认）时装配
  - 内置 `AuditLogHook` + `AuditPlugin`：工具调用/结果追加 JSONL
    （`AGENT_AUDIT` 默认 on，`~/.php-agent/sessions/audit.jsonl`）
  - `HookPipeline` 统一事件过滤与顺序执行
  - 测试 21/21（+7）：否决传播、审计 JSONL、上下文注入引用传播、
    after_turn payload、ConfirmHook 状态机、before_llm_request 否决
  - 验证：ask 模式只读工具不打扰 ✓；非交互终端危险工具自动拒绝 ✓；
    off 模式直通 ✓；审计 JSONL 配对记录 ✓；DeepSeek 真实回归 ✓

- **插件架构 Phase 1**（设计文档 [docs/plugin-design.md](docs/plugin-design.md)）
  - Kernel 层：`Plugin` 接口 + `AgentKernel` 注册容器 + `PluginManager` 发现加载
    （内置 → 项目 `plugins/` 目录，`AGENT_PLUGINS` 可 off/白名单过滤，composer 包发现留待 Phase 3）
  - 五个扩展点：`registerProvider`（惰性工厂、后者覆盖）/ `registerTool` / `setSessionStore` /
    `registerCommand` / `addHook`（钩子接线在 Phase 2）
  - **会话持久化插件化**：`SessionStore` 接口 + 四实现——`InMemory`（不持久化）、
    `JsonFile`（默认，~/.php-agent/sessions/，原子写、人可读）、`Sqlite3`（单文件 db）、
    `Redis`（多进程/常驻场景）；`AGENT_SESSION_STORE` 一行切换
  - 内置插件化：CoreTools / Anthropic / OpenAICompat / Fake / Session / Commands 六个内置插件；
    `bin/agent`、`bin/server` bootstrap 收敛为「建 kernel → 加载插件 → 取用」
  - CLI 新命令：`/sessions`、`/resume [id]`、`/new`、`/plugins`；每轮任务后自动保存会话，
    失败回滚不污染历史；跨进程 `/resume` 续聊
  - Server：内存数组换 `SessionStore`，重启不丢会话
  - 用户示例插件 `plugins/example/`（重命名 plugin.php.disabled 启用）
  - 测试 14 个（原 6 + 新 8）：存储统一契约（含 redis 探活跳过）、插件发现/off/白名单、
    provider 惰性与覆盖语义
- **验证**：CLI 跨进程 /resume 恢复 6 条历史 ✓；server 重启后同 session 继续对话 ✓；
  DeepSeek deepseek-flash 真实回归（工具调用 + 流式）✓

### 修复（DeepSeek 真实环境实测发现）

- **curl 句柄在回调上下文中被关闭**（`curl_close(): Attempt to close cURL handle from a callback`）：
  SSE 流在 `[DONE]` 后提前返回时，Fiber 仍挂起在 curl 回调里，生成器销毁时执行清理会触发
  PHP 禁止的回调内 close。改为先把传输入为中止（WRITEFUNCTION 返回 0 + XFERINFOFUNCTION
  兜底），让 `curl_exec` 跑完、Fiber 终结后再关闭句柄。
- **OpenAI 兼容协议工具参数丢失**（工具入参全变成 `[]`）：流式 tool call 的后续
  arguments 增量只带 `index` 不带 `id`，原实现按 id 做 key 导致增量落到错误的桶。
  改为按协议规定以 `index` 为累积 key。
- **Fiber 销毁上下文限制**（`Cannot switch fibers in current execution context`）：
  通过最小复现脚本确认——流式生成器在挂起中途被销毁时（提前 return / 中途 throw /
  消费者抛弃），PHP 禁止切 Fiber，任何清理都会失败。修复三层：
  1. 客户端 stream() 用 try/finally 把底层流**拉到自然结束**（drain），提前 return /
     中途异常均安全（经复现脚本验证异常展开的 finally 里 drain 合法）；
  2. AgentLoop 消费循环体内**绝不抛异常**——流内 error 事件、UI 回调异常都先记录、
     流结束后再抛，保证客户端生成器永远被消费到底；
  3. postSse 的清理加兜底 try/catch，清理失败不掩盖原异常（调用方违约时放弃句柄）。

### 计划

- 会话持久化（SQLite）
- 流式 diff 渲染（write_file 展示修改前后对比）
- MCP 客户端接入（logiscape/mcp-sdk-php）
- 长会话上下文压缩
- TUI 升级（php-tui）、桌面壳（Tauri sidecar / FrankenPHP 打包）

## [0.1.0] - 2026-11-11

首个 MVP 版本：终端/本地服务双入口的 PHP coding agent 内核。

### 新增

- **Agent 核心循环**（`src/Agent/AgentLoop.php`）
  用户输入 → LLM 流式响应 → 工具调用 → 结果回填 → 循环，直到给出最终回答；
  与 provider 完全解耦（归一化消息格式），带最大轮数熔断（默认 25）。
- **LLM 客户端**（`src/LLM/`）
  - `AnthropicClient`：Anthropic Messages API 原生 SSE 协议
  - `OpenAICompatClient`：OpenAI 兼容协议，覆盖 GLM / DeepSeek / Kimi / Qwen / OpenAI，
    支持推理模型思维链（`reasoning_content`）
  - `FakeLLMClient`：离线演示脚本（`php bin/agent --fake` 免 API key 体验全流程）
  - 流式实现：curl WRITEFUNCTION + PHP 8.1 原生 Fiber 反转控制流，逐 token 输出，零框架依赖
- **统一事件协议**（`src/LLM/StreamEvent.php`）
  `text_delta` / `thinking_delta` / `tool_use_*` / `tool_result` / `message_end` / `final` / `error`，
  CLI 渲染与 SSE 服务共用同一套事件流。
- **工具层**（`src/Tools/`，工作目录沙箱）
  `read_file`（行号 + offset/limit）、`write_file`（自动建父目录）、`list_dir`、
  `run_command`（60s 超时、stdin=EOF、输出截断 32KB、非零退出标记 ERROR）、
  `grep`（PCRE 递归搜索，跳过 .git/node_modules，上限 200 条）。
- **CLI 交互**（`bin/agent` + `src/Ui/ConsoleUi.php`）
  ANSI 彩色流式渲染、工具调用与结果实时展示、`/help` `/tools` `/reset` `/exit` 命令、
  非 tty（管道）输入自动退回 fgets。
- **本地 SSE 服务**（`bin/server` + `src/Server/AgentServer.php` + `public/index.html`）
  ReactPHP HTTP 服务：`GET /` 内置 Web 聊天页（fetch 流式消费），
  `POST /agent` SSE 事件流（按 `session_id` 保持会话），为接入 Tauri/Electron 桌面壳预留对接面。
- **配置**（`src/Config.php` + `.env.example`）
  provider / base_url / model / max_tokens / max_iterations / workspace / 端口，
  五家 provider preset 注释齐全。
- **测试**（`tests/`）
  PHPUnit 6 个离线测试：agent 循环（工具执行/消息结构/事件流）、熔断保护、
  错误传播、SSE 解析（分块/跨包/边界）。

### 开发过程中修复

- macOS `/var → /private/var` 符号链接导致沙箱路径比对误判：`WorkspaceTool` 构造时先
  realpath 归一化工作目录。
- `write_file` 无法创建嵌套新目录（父目录不存在时 realpath 失败）：改为逐级向上找
  已存在祖先目录再拼回剩余路径。
- `run_command` 退出码可能返回 -1：改为从 `proc_get_status` 首次捕获真实 exit code。

### 验证

- PHPUnit：6 tests / 25 assertions 全部通过
- 端到端（fake provider）：写文件 → 运行 `php demo/hello.php` → 输出 `Hello from PHP Agent!`（exit 0）
- SSE 服务：`POST /agent` 完整事件流（tool_use → result 回填 → 流式文本 → final 帧）

[Unreleased]: https://example.invalid/php-agent/compare/v0.1.0...HEAD
[0.1.0]: https://example.invalid/php-agent/releases/tag/v0.1.0
