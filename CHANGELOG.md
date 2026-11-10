# 更新日志

本项目所有显著变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

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
