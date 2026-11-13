# MCP 配置管理（桌面设置入口）设计

日期：2026-11-13
状态：待确认

## 意图

桌面端想配 MCP 目前只能手动编辑各项目目录的 `.mcp.json`。本设计在设置对话框
（标题栏齿轮 → 「平台管理」）中增加 MCP 配置区，可视化增删改 MCP server，
并支持全局配置让所有项目共享。

## 方案取舍

- **仅项目 `.mcp.json`**：零后端改动，但全局 server（如 filesystem）每个项目都要配一遍。
- **仅全局配置**：后端 McpConfig 改动小，但失去项目级差异化能力。
- **全局 + 项目覆盖（已选）**：全局一份共享，项目可覆盖同名 server 或追加；两层都可视化管理。
- 热更新（POST /config/mcp + McpPlugin 运行时重连）体验最好，但要改 McpPlugin
  生命周期、断开旧连接重挂工具，风险大收益低（保存不频繁），**不做**，重启项目生效。

## 配置存储

| 层 | 文件 | 格式 |
|---|---|---|
| 全局 | `~/.php-agent/mcp.json` | `{"mcpServers": {…}}`（新文件） |
| 项目 | `<workspace>/.mcp.json` | 现有格式不变，与 Claude Code / Cursor 互通 |

- 两个文件格式完全一致：`mcpServers` 下每条目 `command`+`args`+`env`（stdio）
  或 `url`+`headers`（http），可选 `timeout`。
- 合并语义：项目条目**整条覆盖**同名全局条目（非字段级合并）；其余并集。

## PHP 改动（src/Mcp/McpConfig.php）

- `load($workspace)` 增加全局层：先加载 `~/.php-agent/mcp.json`（新增
  `McpConfig::globalPath()` 便于测试注入），再加载 workspace 文件，项目覆盖同名。
- 错误语义沿用：文件缺失静默；JSON 损坏抛异常（McpPlugin 统一警告）；
  单条目非法收进 errors。全局文件损坏同样抛异常，消息标注来源文件。
- 不新增 HTTP 端点（不做热更新）；`/status` 不变。
- McpPlugin 无需改动（load 内部签名不变）。

## Tauri 改动（src-tauri/src/lib.rs）

新增三个 invoke 命令（读写文件，不做校验以外的逻辑）：

- `get_mcp(workspace: Option<String>)` → `{ global: McpServers, project: McpServers | null }`
  （无打开项目时 project 为 null）；解析失败返回错误消息。
- `save_mcp_global(servers)` → 写 `~/.php-agent/mcp.json`
- `save_mcp_project(workspace, servers)` → 写 `<workspace>/.mcp.json`

写入前校验（镜像 PHP 语义）：名称非空且唯一、command/url 二选一、timeout ≥ 1。

## 前端改动（desktop/src）

- SettingsDialog 加 Tab 切换：「平台」/「MCP」（轻量实现，不引依赖）。
- MCP Tab 两个区块：
  - **全局 MCP**：server 列表卡片，每条含 名称、类型 select（stdio/http）、
    stdio→command + args（一行一个）+ env（KEY=VALUE 一行一个）、
    http→url + headers（KEY: VALUE 一行一个）、timeout（秒，默认 30）。
  - **当前项目 MCP**：同表单；无打开项目时置灰并提示。
- 保存：分别 invoke 保存全局/项目；成功提示「重启项目后生效」。
- store：useUiStore 增加 mcpPayload 缓存（打开时回填，保存后刷新），模式同 profilesPayload。

## 测试

- PHP：McpConfigTest 补全局加载、项目覆盖全局、全局损坏抛异常用例。
- Rust：lib.rs 补 get/save mcp 文件读写与校验用例。
- 前端：SettingsDialog.test.tsx 补 Tab 切换、MCP 编辑保存用例。

## 非目标

- 不做运行中热更新（重启项目生效，重启按钮已有）。
- 不做 per-server 启用开关（删条目即可）。
- Web 聊天页不加 MCP 管理。
- 不改 MCP transport / 连接逻辑。
