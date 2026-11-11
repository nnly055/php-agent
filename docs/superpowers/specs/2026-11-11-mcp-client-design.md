# MCP 客户端设计（Phase 1：tools + stdio + Streamable HTTP）

日期：2026-11-11
状态：已确认（用户批准）

## 背景与目标

php-agent 目前的工具来自内置五件套与插件。接入 MCP（Model Context Protocol）
后，agent 可以把任意外部 MCP server 的工具挂进自己的 ToolRegistry，
获得生态里现成的 server（filesystem、git、fetch 等）。

首版范围（与用户确认）：

- 只消费 **tools**（resources / prompts / sampling 等不做）
- 支持 **stdio**（本地子进程）与 **Streamable HTTP**（远程）两种传输
- 配置文件 `.mcp.json`，格式与 Claude Code / Cursor 兼容
- 权限：server 声明 `readOnlyHint` 的工具自动放行，其余走现有 ask 确认

## 总体架构与数据流

```
启动: bin/agent → PluginManager::load() → McpPlugin::register()
  → McpConfig 读 <workspace>/.mcp.json
  → 每个 server 建一条 McpConnection（StdioTransport 或 HttpTransport）
  → initialize 握手 → notifications/initialized → tools/list（带分页）
  → 每个工具注册一个 McpToolAdapter 到 ToolRegistry

调用: LLM 决定调 mcp__<server>__<tool>
  → ToolRegistry.execute → McpToolAdapter.execute(input)
  → connection.callTool(name, args) → Transport 收发 JSON-RPC
  → content[] 提取文本返回；isError=true 时抛异常 → "ERROR: ..." 回传模型

退出: register_shutdown_function 逐个 close()（stdio 杀子进程）
```

- 连接在**启动时立即建立**（工具清单必须进第一轮 LLM 请求）。
  失败的 server 警告后跳过，不影响其他工具。
- 不做运行时重连：子进程式 server 重连成本高、状态不可控，v1 只标记 dead 快速失败。

## 新增文件

```
src/Mcp/
├── McpConfig.php          # 加载解析 .mcp.json
├── Transport.php          # 接口：request(array $payload, float $timeout): ?array；close(): void
├── StdioTransport.php     # proc_open 子进程，按行读写 JSON-RPC
├── HttpTransport.php      # curl，Streamable HTTP
├── McpConnection.php      # 握手 / tools(list|call) / 状态记录
└── McpToolAdapter.php     # 实现 Agent\Tool 与 ReadOnlyTool
src/Plugins/McpPlugin.php  # 读配置 → 连接 → 注册适配器 + /mcp 命令 + 关闭钩子
src/Agent/ReadOnlyTool.php # 标记接口：readOnly(): bool
```

修改：`ToolRegistry`（加 `find()`）、`ConfirmHook`（可选 `?ToolRegistry` 参数）、
`PluginManager::builtin()`（追加 McpPlugin）、`bin/agent`（ConfirmHook 补传 registry）、
`Config`（`mcpEnabled()`）、`README.md`、`.env.example`。

## 配置格式（`<workspace>/.mcp.json`）

```json
{
  "mcpServers": {
    "filesystem": { "command": "npx", "args": ["-y", "@modelcontextprotocol/server-filesystem", "/tmp"] },
    "remote":     { "url": "https://mcp.example.com/mcp", "headers": { "Authorization": "Bearer xxx" } },
    "local":      { "command": "php", "args": ["server.php"], "env": { "FOO": "bar" }, "timeout": 60 }
  }
}
```

- 有 `command` → stdio；有 `url` → Streamable HTTP；两者都有/都无 → 该 server 报错跳过
- `timeout`（秒，默认 30）作用于握手与每次工具调用；`env` 合并到子进程环境
- `AGENT_MCP=off` 彻底禁用（对齐 `AGENT_PLUGINS` / `AGENT_AUDIT` 风格）
- 文件不存在时插件静默 no-op（大多数用户不配 MCP，不产生噪音）

## 传输层

### StdioTransport（proc_open）

- 描述符 0/1/2；stderr 捕获到缓冲区，连接失败时随警告输出
- 按行读（`stream_get_line`，10MB 上限防失控输出）
- 等待响应用 `stream_select` 实现超时
- 读循环：`id` 匹配的 message 返回；无 `id` 的 notification 忽略（暂存最近几条供 /mcp 诊断）
- 子进程环境 = 当前环境 + 配置 `env`

### HttpTransport（curl，Streamable HTTP）

- `POST` JSON-RPC 到 url，头：`Content-Type: application/json`、
  `Accept: application/json, text/event-stream`
- 响应可能是单条 `application/json` 或 `text/event-stream`，两者都解析（SSE 只取 `data:` 行）
- `initialize` 后保存响应的 `Mcp-Session-Id`，后续请求带回，并附 `MCP-Protocol-Version` 头
- 不做 GET 长连接监听（v1 只做工具消费，不支持 sampling / roots 等服务端反调）

## 协议层（McpConnection）

- `initialize`：发 `protocolVersion: "2025-06-18"`，以 server 应答为准
- 握手成功后发 notification `initialized`
- `tools/list`：处理 `nextCursor` 分页直到取完
- `tools/call`：`{name, arguments}`；自增 id 关联响应
- 状态记录：connected / failed / dead / 工具数 / 最近错误，供 `/mcp` 展示

### McpToolAdapter（实现 `Agent\Tool`）

- `name()` = `mcp__<server>__<tool>`（对齐 Claude Code 惯例；server/tool 名只保留
  `[A-Za-z0-9_-]`，其余字符替换为 `_`，避免 LLM 侧工具名非法）
- `description()` = server 描述；`schema()` = `inputSchema` 透传
  （缺省 `{"type":"object","properties":{}}`）
- `execute()`：拼接 `content[]` 中 `type=text` 的 `text`（换行连接）；
  只有 `structuredContent` 时 `json_encode`；非文本块记 `[image 等非文本内容]` 占位；
  `isError=true` 或传输异常 → 抛 `\RuntimeException`，由 ToolRegistry 统一转 `ERROR:` 文本

## 集成点

- **只读放行**：新增 `PhpAgent\Agent\ReadOnlyTool` 接口（`readOnly(): bool`）；
  `ToolRegistry::find(name): ?Tool`；ConfirmHook 构造加可选 `?ToolRegistry`，
  放行条件从「硬编码名单」扩为「名单 ∨ `instanceof ReadOnlyTool && readOnly()`」。
  内置五件套不动，仍走名单。McpToolAdapter 按 `readOnlyHint` 实现（未声明 = 需确认）。
- **`/mcp` 命令**：McpPlugin 注册，输出各 server 状态 / 传输类型 / 工具数，失败带原因
  （含 stderr 捕获内容）。
- **进程清理**：McpPlugin `register()` 末尾 `register_shutdown_function`，
  逐连接 `close()`（`proc_terminate` + `proc_close`）。
- **加载顺序**：McpPlugin 追加到 `PluginManager::builtin()` 清单末尾。
  bin/agent 的 ConfirmHook 构造处补传 `$kernel->toolRegistry()`。
- **server 复用**：`bin/server` 走同一装配自动生效；无交互确认的既有行为对 MCP 工具
  同样适用（README 写明），审计日志照常记录。

## 错误处理

| 场景 | 行为 |
|---|---|
| `.mcp.json` 缺失 | 静默跳过 |
| JSON 损坏 | stderr 警告，跳过全部 MCP |
| 单个 server 握手失败/超时 | stderr 警告 + 标记 failed，其余照常 |
| 工具调用超时/进程死 | 抛异常 → `ERROR:` 回传模型；连接标记 dead 快速失败 |
| server 返回 `isError` | 工具文本内容原样抛出给模型（模型可修正入参重试） |

## 测试策略

- **单测**：McpConfig（三种形态 + 非法输入）、McpToolAdapter（命名 / 只读 /
  content 提取 / isError）、McpConnection（注入 FakeTransport：握手、分页、
  notification 混杂、超时）
- **集成**：`tests/fixtures/mcp-fake-server.php`（真子进程讲真 stdio 协议）走
  StdioTransport 全链路；`php -S` 本地 HTTP fixture 走 HttpTransport
  （含 `Mcp-Session-Id` 与 SSE 响应形态）；McpPlugin 端到端
  （临时 .mcp.json → ToolRegistry 拿到工具并执行）
- ConfirmHook 补测：readOnlyHint 工具放行、未声明工具确认

## 明确不做（v1）

- resources / prompts / sampling / roots / completion 等 MCP 能力
- 服务端向客户端的反向请求（sampling、elicitation）
- 运行时重连与热重载配置
- 旧版 HTTP+SSE 传输（2024-11-05，已废弃）
