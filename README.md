# PHP Agent

[![CI](https://github.com/nnly055/php-agent/actions/workflows/ci.yml/badge.svg)](https://github.com/nnly055/php-agent/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

用纯 PHP 实现的 coding agent（对标 opencode / Claude Code 的核心机制）：
**LLM 流式对话 + 工具调用循环 + 插件化持久化 + 本地 SSE 服务**。

- 终端交互：`php bin/agent`，流式输出、彩色工具调用展示
- 本地服务：`php bin/server`，内置 Web 聊天页，同时作为桌面壳的后端提供 HTTP+SSE 接口
- 桌面应用：`cd desktop && npm run tauri dev`，Tauri 壳自动拉起 PHP sidecar（见「桌面应用」）
- 多 Provider：Anthropic 原生协议 + OpenAI 兼容协议（覆盖 GLM / DeepSeek / Kimi / Qwen / OpenAI）
- 零框架：核心代码仅依赖 curl（Fiber 流式）与 ReactPHP（本地服务）

## 环境要求

- PHP ≥ 8.1（需 `curl`、`json`、`mbstring` 扩展）
- Composer

## 快速开始

```bash
cd php-agent

# 1. 安装依赖
composer install

# 2. 配置 provider（参考 .env.example，任选一个 preset）
cp .env.example .env
# 编辑 .env：AGENT_API_KEY + AGENT_BASE_URL + AGENT_MODEL

# 3a. 离线体验（不需要 API key，走内置演示脚本）
php bin/agent --fake

# 3b. 真实对话
php bin/agent

# 3c. 本地服务 + 网页聊天
php bin/server
# 打开 http://127.0.0.1:8091
```

### 支持的 Provider preset

| Provider | AGENT_PROVIDER | AGENT_BASE_URL | AGENT_MODEL 示例 |
|---|---|---|---|
| 智谱 GLM | `openai` | `https://open.bigmodel.cn/api/paas/v4` | `glm-4.6` |
| DeepSeek | `openai` | `https://api.deepseek.com/v1` | `deepseek-chat` |
| Kimi | `openai` | `https://api.moonshot.cn/v1` | `kimi-k2-0905-preview` |
| OpenAI | `openai` | `https://api.openai.com/v1` | `gpt-4o` |
| Anthropic | `anthropic` | `https://api.anthropic.com` | `claude-sonnet-4-5` |

## CLI 命令

| 命令 | 说明 |
|---|---|
| `/help` | 帮助（列出全部命令，含插件注册的） |
| `/tools` | 列出已注册工具 |
| `/plugins` | 已加载插件 + 当前会话存储 + provider |
| `/sessions` | 列出最近会话 |
| `/resume [id]` | 恢复会话（无参数=最近一个） |
| `/new` | 开启新会话 |
| `/reset` | 清空当前会话历史 |
| `/mcp` | 查看 MCP server 状态与工具清单 |
| `/exit` | 退出（Ctrl+D 同效） |

会话在每轮任务结束后自动保存；CLI 重进 `/resume` 续聊，server 重启不丢会话。

### 权限模式与审计

```bash
AGENT_PERMISSIONS=ask   # 默认：write_file/run_command 执行前 CLI 确认 [Y/n/a]，
                        # a=本次会话总是允许；只读工具（read_file/list_dir/grep）自动放行；
                        # 非交互终端自动拒绝
AGENT_PERMISSIONS=off   # 全部放行
AGENT_AUDIT=on          # 默认：每次工具调用/结果追加写入 ~/.php-agent/sessions/audit.jsonl
```

`AGENT_PERMISSIONS=ask` 时，`write_file` 授权确认前会先展示**变更预览**（红绿 diff），
即「先看改了什么、再决定 Y/n/a」。该能力由内置的 diff 插件提供
（[plugins/diff/](plugins/diff/)，同时是「工具装饰器 + 生命周期钩子」双扩展点的参考实现），
重命名 `plugin.php → plugin.php.disabled` 可停用。

server 端通过 `confirm_request` SSE 事件 + `POST /confirm/{id}` 把授权决定交给前端
（桌面壳已实现授权弹窗，diff 由前端按行上色渲染）；审计对 CLI/server 同时生效。

### 上下文压缩

会话历史增长到接近模型窗口时，`compact` 插件会在下一轮请求前自动把早期历史
用当前 LLM 压缩成一条摘要（保留当前任务原文，绝不切开工具调用配对），
CLI/Web 都会收到 ℹ 提示；`/compact` 可随时手动触发。

```bash
AGENT_CONTEXT_TOKENS=128000     # 模型上下文窗口（按所用模型调整）
AGENT_COMPACT_THRESHOLD=0.8     # 估算达到窗口 80% 时自动压缩
# AGENT_COMPACT_PROVIDER=       # 压缩用 provider，空 = AGENT_PROVIDER；离线演示设 fake
```

token 为保守估算（字符数 ÷ 2.5，无 tokenizer）；压缩就地修改历史，
持久化与 /resume 自动一致。`/compact` 与斜杠命令为 CLI 专属（server 无命令分发）；
就地压缩的历史在下一次成功对话轮结束后落盘（`bin/agent` 每轮成功后自动保存）。
停用：重命名 plugins/compact/plugin.php 为
plugin.php.disabled。该插件同时是 `before_llm_request` 钩子
（引用裁剪历史）+ `/compact` 命令双扩展点的参考实现。

## 插件系统

核心只提供「接口 + 注册容器 + 发现机制」，五个扩展点全部可插拔：
LLM provider、工具、**会话持久化**、斜杠命令、生命周期钩子（Phase 2 接线）。

```bash
# 切换持久化后端（.env 一行）
AGENT_SESSION_STORE=json|memory|sqlite3|redis
```

设计文档见 [docs/plugin-design.md](docs/plugin-design.md)。写一个插件只需两步：

### 方式一：脚手架生成（推荐）

```bash
php bin/agent --plugin:new my-tool
# → plugins/my-tool/ 骨架（示例工具 my-tool_greet + 示例命令 /my-tool），生成即自动启用
# → /tools、/help 立即可见，改掉示例实现就是你的插件
```

### 方式二：手工创建

1. `plugins/<name>/src/MyPlugin.php` 实现 `PhpAgent\Kernel\Plugin`
2. `plugins/<name>/plugin.php` 返回插件类名（[完整示例](plugins/example/)，把
   `plugin.php.disabled` 重命名为 `plugin.php` 即可启用）

### composer 包分发

第三方包在自家 composer.json 里声明，`composer require` 后自动发现加载：

```json
{
    "extra": {
        "php-agent": { "plugins": ["Vendor\\PhpAgent\\MyPlugin"] }
    }
}
```

发现读取 `vendor/composer/installed.json`（不依赖 Composer 运行时）。三层加载顺序：
内置 → composer 包 → 项目 `plugins/`，后注册者覆盖同 id——所以项目插件可以覆盖
composer 包的实现。`AGENT_PLUGINS=off` 关闭 2/3 层；逗号列表按插件 name 白名单过滤。

给第三方开发者的完整接入手册（含 MySQL 持久化插件参考实现）见
[docs/plugin-dev-guide.md](docs/plugin-dev-guide.md)。

## MCP 工具（客户端）

在工作区根目录放 `.mcp.json`（格式与 Claude Code / Cursor 兼容），启动即自动连接并把
server 的工具挂进工具列表（`/tools` 可见，名称形如 `mcp__<server>__<tool>`）：

```json
{
  "mcpServers": {
    "filesystem": { "command": "npx", "args": ["-y", "@modelcontextprotocol/server-filesystem", "/tmp"] },
    "remote": { "url": "https://mcp.example.com/mcp", "headers": { "Authorization": "Bearer xxx" } }
  }
}
```

- 传输：`command` = 本地 stdio 子进程；`url` = Streamable HTTP（远程）
- 每条目可选 `timeout`（秒，默认 30）、`env`（stdio 环境变量）、`headers`（HTTP 请求头）
- 权限：server 声明 `readOnlyHint: true` 的工具自动放行，其余在 ask 模式下先确认
- `/mcp` 查看 server 状态与工具清单；`AGENT_MCP=off` 整体禁用
- 退出时自动清理 stdio 子进程；单个 server 连接失败不影响其余工具
- 首版仅消费 tools 能力（resources / prompts / sampling 暂不支持），协议版本 2025-06-18

## 内置工具

| 工具 | 功能 | 安全约束 |
|---|---|---|
| `read_file` | 读文件（带行号，支持 offset/limit） | 限制在工作目录内 |
| `write_file` | 写文件（自动创建父目录），**覆盖已有文件时返回红绿 diff** | 限制在工作目录内 |
| `list_dir` | 浏览目录 | 限制在工作目录内 |
| `run_command` | 执行 shell 命令 | 工作目录内运行；stdin=EOF；默认 60s 超时；输出截断 32KB；非零退出标记 ERROR |
| `grep` | 递归正则搜索（PCRE） | 跳过 .git/node_modules；上限 200 条 |

工作目录 = 启动 agent 时的当前目录（或 `.env` 里的 `AGENT_WORKSPACE`）。
`run_command` 能通过 shell 触达整个系统，请在可信项目里使用。

## 架构

```
┌────────────────────────── bin/agent (CLI) ──────────────────────────┐
│  ConsoleUi (ANSI 流式渲染)                                           │
└──────────────────────────────┬──────────────────────────────────────┘
┌────────────────── desktop/ (Tauri 桌面壳) ──────────────────────────┐
│  React 前端（流式聊天/授权弹窗/会话/workspace）                      │
│  Rust 壳：sidecar 生命周期管理 ←HTTP+SSE→ bin/server                │
└──────────────────────────────┬──────────────────────────────────────┘
┌────────────────── bin/server (本地服务) ─────────────────────────────┐
│  AgentServer: /status · /sessions · /confirm · POST /agent SSE      │
│  （ReactPHP+Fiber，内置 Web 聊天页）                                 │
└──────────────────────────────┬──────────────────────────────────────┘
                        AgentLoop (agent 循环)
        user 输入 → LLM 流式 → tool_use → 执行 → tool_result 回填 → …
                        │                       │
              LLMClient 接口            ToolRegistry 接口
        ├ AnthropicClient (原生 SSE)   ├ read_file / write_file
        ├ OpenAICompatClient (兼容)    ├ list_dir / grep
        └ FakeLLMClient (离线)         └ run_command
```

关键实现：

- **Fiber 流式**（`src/LLM/BaseSseClient.php`）：curl 的 WRITEFUNCTION 是回调式的，
  无法直接被 generator 消费——用 PHP 8.1 原生 Fiber 反转控制流：回调里 `Fiber::suspend()`，
  外层循环消费 chunk 后 `resume()`，实现真正的逐 token 流式，零第三方依赖。
- **归一化消息格式**（`src/LLM/LLMClient.php`）：内部统一用 Anthropic 块结构，
  各客户端负责转成自家协议，AgentLoop 与 provider 完全解耦。
- **统一事件协议**（`src/LLM/StreamEvent.php`）：`text_delta` / `tool_use_*` / `tool_result` /
  `message_end` / `final` / `error`——CLI 渲染与 SSE 服务共用同一套事件。
- **沙箱路径解析**（`src/Tools/WorkspaceTool.php`）：realpath 归一化 + 逐级向上找祖先目录
  （支持 write_file 创建嵌套新目录），所有文件操作限制在工作目录内。

## 测试

```bash
composer test                          # 后端：vendor/bin/phpunit（123 个测试）
cd desktop && npm test                 # 前端：vitest（31 个测试）
cd desktop/src-tauri && cargo test     # Rust 壳（2 个测试）
```

后端 123 个测试覆盖：agent 循环（工具执行/消息结构/事件流）、最大轮数熔断、错误传播、
SSE 解析（分块/跨包/边界）、钩子管线与权限确认、插件发现与加载顺序、会话存储统一契约、
配置解析、上下文压缩（token 估算/边界/替换/失败语义/钩子接线/节流）、
diff 插件与 /compact 命令、MCP 客户端（stdio/HTTP 传输、连接、权限、插件与工具适配）、
本地服务（路由/CORS/确认代理/SSE 集成）。

前端 31 个测试覆盖（vitest + Testing Library）：SSE 帧解析、diff 解析与渲染、
zustand store、流式/探活 hooks、输入框、授权弹窗、会话条目、连接横幅。

Rust 2 个测试覆盖：端口行解析、sidecar 真实启动/停止生命周期（含无孤儿验证）。

## SSE 接口（对接桌面前端）

```bash
curl -N -X POST http://127.0.0.1:8091/agent \
  -H 'Content-Type: application/json' \
  -d '{"message": "列出当前目录", "session_id": "web-1"}'
```

返回 `text/event-stream`，每帧 `data: {"type": "...", "data": {...}}`，
事件类型与 `StreamEvent` 一致；会话历史按 `session_id` 经会话存储持久化
（默认 json，`AGENT_SESSION_STORE` 一行切换）。ask 模式下工具执行前先发
`confirm_request` 事件，客户端 `POST /confirm/{id}` 回 `{"decision":"y|n|a"}` 后继续。

## 桌面应用

Tauri 2 桌面壳（`desktop/`）：React 前端 + Rust 壳，复用 `php bin/server` 作为 sidecar。
**多项目管理**：每个项目一个独立 PHP 进程（独立端口 / 工作区沙箱 / 会话存储目录），
项目间真并行——一个项目跑长命令不会卡住另一个项目；同一项目内串行执行避免编辑冲突。

```bash
cd desktop
npm install
npm run tauri dev
```

环境要求：Rust stable（含 cargo）+ Node 22 + 系统 PHP（≥ 8.1，curl/json/mbstring），
项目根先 `composer install`。

- **多项目标签页**：「添加项目」选目录即拉起专属 sidecar（`AGENT_SERVER_PORT=0`
  动态端口，从 stdout 的 `AGENT_LISTENING port=<port>` 行解析探活；`AGENT_WORKSPACE`
  为项目目录；`AGENT_SESSION_DIR=~/.php-agent/projects/<slug>/sessions` 会话按项目隔离）
- 同一项目重复打开自动复用存活进程；标签关闭 = 进程退出、会话记录保留，可从折叠列表恢复
- 每项目前端状态独立（zustand 多实例 + Context）：流式聊天、Markdown/diff 渲染、
  按需授权弹窗（Y/N/A + 变更预览）、会话列表/切换/删除、断开横幅 + 分会话重连
- 退出时全部 sidecar 按进程组整组清理（含 MCP stdio 子进程），无孤儿进程
- dev 模式使用系统 PHP；**打包分发（免系统 PHP 依赖）**见下节

### 打包分发

```bash
bash desktop/scripts/bundle-backend.sh   # 组装 PHP 后端：静态 php + 内核代码 → resources/php-agent/
npm run tauri build                       # 产出 .app / .dmg（macOS）
```

- **静态 PHP**：取 [static-php-cli](https://github.com/static-php/static-php-cli) 预编译二进制
  （默认 8.2.31，13MB，含 curl/mbstring/sqlite3/redis/pcntl 等全部必需扩展），带本地缓存与
  下载验证（重试 + 文件格式校验），可用 `PHP_VERSION` / `FORCE_PHP=1` 控制
- **多平台 CI**：推 `v*` tag 或手动 dispatch 触发 [.github/workflows/release.yml](.github/workflows/release.yml)——
  macOS（Apple Silicon/Intel dmg）、Linux（AppImage/deb）、Windows（msi/nsis）四平台并行构建，
  产物自动挂到该 tag 的 Release；dispatch 留空 tag = 仅验证构建（产物在 run Artifacts）
- 内核代码目录随 bundle 进 resources（src/bin/plugins/public/vendor 原样保留，
  插件发现与 public 资源结构不变）；产物不入库（.gitignore），构建时重新生成
- Rust 侧 PHP 解析三级回退：`PHP_AGENT_PHP` 环境变量 → bundle 资源里的静态 php
  （启动前自动 chmod 755）→ 系统 `php`（dev 兜底）
- 平台差异：static-php 官方无 Windows 预编译产物，**Windows 打包使用系统 PHP**
  （CI runner 由 setup-php 提供；本机打包需自装 PHP ≥ 8.1），macOS/Linux 保持零系统依赖

## 路线图

- [x] 桌面壳 MVP：Tauri sidecar 加载 PHP 内核（dev 模式，见「桌面应用」；打包分发见下条）
- [x] 桌面壳打包分发：静态 PHP sidecar（static-php-cli 产物）+ 资源目录 bundle，免系统 PHP 依赖（见「打包分发」）
- [x] MCP 客户端接入（tools + stdio + Streamable HTTP，零依赖内置，见上方「MCP 工具（客户端）」）
- [x] 流式 diff 渲染（write_file 变更可视化，授权前 diff 预览 + 结果 diff，见「权限模式与审计」）
- [x] 长会话上下文压缩（compact 插件：阈值自动触发 + /compact 手动，见「上下文压缩」）
- [ ] TUI 升级（php-tui）

已实现：会话持久化插件化（json / sqlite3 / redis / memory 一行切换）、
钩子系统（工具授权确认、审计日志）、composer 包插件发现、插件脚手架、
MCP 客户端（stdio + Streamable HTTP）、上下文压缩、write_file 变更可视化、
桌面壳 MVP（Tauri + React，dev 模式）。
