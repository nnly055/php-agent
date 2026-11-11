# 桌面壳架构设计（方案 B：每会话一进程）

> 目标：给现有 PHP agent 内核套 Tauri 桌面壳，多会话并行、权限交互、diff 可视化全部上桌面。
> 核心决策：**会话进程隔离**——每个会话一个无头 PHP 进程，Tauri(Rust) 直接管理进程池。

## 0. 循环拓扑（本设计对「循环嵌套」问题的最终回答）

现状是两层嵌套循环：入口层循环（CLI REPL / ReactPHP 事件循环）⊃ AgentLoop 工具循环，
工具是叶子。桌面壳落地后拓扑变为**三层、跨进程**：

```
Tauri webview                    前端 UI 循环（DOM/事件驱动，多会话标签）
   ↕ stdin/stdout JSON-L（Rust 转发）
session-worker × N               每会话一进程：worker REPL 循环（stdin 请求驱动）
   └── AgentLoop::run()          内层工具循环（LLM→工具→回填）
         └── 工具                 叶子（同步执行，只冻结自己所在会话）
```

未来的 pi 式 subagent = worker 进程**内部**再嵌套 AgentLoop（工具触发的子任务，
事件带命名空间冒泡到前端），不影响壳架构——这正是进程隔离的红利。

## 1. 为什么选 B（对比结论）

| 维度 | A 单进程多 Fiber | B 每会话一进程 ★ | C MVP 壳 |
|---|---|---|---|
| 并发真相 | 伪并发：LLM 等待/工具执行冻结全循环 | 真隔离：互不影响 | 同 A |
| 内核改造 | 大（curl_multi/proc 接事件循环） | **零**（CLI 内核直接复用） | 零 |
| 崩溃/泄漏半径 | 全体会话 | 单会话 | 全体 |
| 内存 | 最小 | ~30MB/会话（可接受） | 最小 |
| subagent 扩展 | fiber 嵌套复杂 | 进程内自然嵌套 | - |

现有 server 模式的三处事件循环冻结点（LLM chunk 间隙、run_command 同步执行、
compact 调用）在 B 下都只影响本会话。

## 2. 组件分层

```
┌─ Tauri 壳 (Rust) ──────────────────────────────┐
│  窗口/托盘/菜单                                  │
│  SessionManager：spawn/kill/监控 worker 进程池    │  ← Rust 侧 ~几百行
│  stdio JSON-L ↔ webview event 双向转发           │
├─ webview 前端 (React/Vue) ─────────────────────┐
│  多会话标签页 · 聊天流 · 权限对话框 · diff 渲染     │  ← 复用 public/index.html 渲染逻辑组件化
├─ session-worker × N (PHP, 无头) ───────────────┤  ← 新增 bin/session-worker
│  AgentKernel + PluginManager（全套插件照常加载）   │
│  AgentLoop 工具循环 · ConfirmHook(headless 变体)  │
│  SessionStore（本地 json/sqlite）                │
└────────────────────────────────────────────────┘
```

关键取舍：**不做 PHP manager 进程**。Tauri 的 sidecar 惯例就是 Rust 直接管子进程，
PHP 侧只交付一个「无头会话进程」，少一层常驻聚合器，链路短、故障点少。

## 3. 会话进程协议（bin/session-worker）

stdin：JSON-L 请求（每行一个）；stdout：JSON-L 事件（复用 StreamEvent 类型体系）。

```jsonc
// → worker（前端发起）
{"type":"run",       "id":"r1", "message":"修复这个 bug…"}
{"type":"confirm",   "id":"r1", "answer":"y"}            // 应答权限询问
{"type":"command",   "name":"compact"}                    // /compact 等命令
{"type":"cancel"}                                          // 预留：中断当前轮
{"type":"shutdown"}

// ← worker（worker 发出）
{"type":"event", "event":{...StreamEvent 同款...}}          // text_delta/tool_use/diff/notice/final
{"type":"ask_permission", "id":"r1", "tool":"write_file", "input":{...}, "diff":"..."}
{"type":"state", "sessionId":"...", "store":"JsonFileSessionStore"}
{"type":"ready"} / {"type":"error", "message":"..."}
```

设计要点：

- **权限交互跨进程化**：worker 内的 ConfirmHook 变体把「读终端 Y/n」换成
  「输出 ask_permission 事件 → 同步等 stdin 的 confirm 行」。worker 单线程阻塞循环，
  同步问答即可，无需 async。
- **流式保真**：text_delta 等事件逐行 flush，前端逐 token 渲染（协议与 SSE 服务同源，
  前端渲染代码可从 public/index.html 平移）。
- **生命周期**：进程启动即输出 ready+state；Rust 侧 on_exit 检测崩溃并通知前端；
  shutdown/窗口关闭时优雅终止（现有 ConfirmHook 的进程清理逻辑沿用）。

## 4. 内核复用映射（改什么、不改什么）

| 现有组件 | 处置 |
|---|---|
| AgentKernel / PluginManager / 全部内置插件 | **零改动** |
| AgentLoop / StreamEvent / LLM 客户端 | **零改动** |
| ConfirmHook | 零改动（prompt 闭包本来就是注入的——worker 注入跨进程问答闭包）|
| SessionStore | 零改动；桌面默认 json（sqlite 多进程写已有 busyTimeout，后续可切）|
| bin/agent、bin/server | 保留（终端与 HTTP 场景继续可用）|
| **bin/session-worker** | **新增**：装配 kernel → stdin REPL 循环 → 协议编解码 |
| ConsoleUi | 不进 worker（worker 无终端 UI，事件直出）|

## 5. 打包分发

- 开发期：系统 `php` 直接跑 worker（macOS 本机即可）
- 分发期：static-php-cli 编译静态 PHP 二进制（curl/json/mbstring/sqlite3）+
  内核打 PHAR → 单文件 `php-agent-worker` 塞进 Tauri `externalBin`（sidecar）
- 前端产物即 Tauri webview 静态资源；安装包 dmg/msi 由 tauri build 出

## 6. 分阶段路线

| 阶段 | 交付 | 验收 |
|---|---|---|
| **A：session-worker** | 无头会话进程 + JSON-L 协议 + 跨进程权限问答 | 纯 PHP 可测：echo 请求进 stdin，事件流出 stdout；权限问答闭环 |
| **B：Tauri 最小壳** | 单窗口单会话：spawn worker、事件转发、基础聊天页 | 桌面上跑通一轮带工具调用的对话 |
| **C：完整体验** | 多会话标签、会话列表（SessionStore::list）、权限对话框、diff/notice 渲染 | 两个会话并行互不阻塞；授权流 + diff 预览在对话框中完成 |
| **D：打包分发** | 静态 PHP sidecar + 安装包 | 无 PHP 环境的机器双击可用 |

## 7. 风险

- worker 单请求串行：会话内「取消正在跑的任务」需要中断机制（cancel 预留，
  实现依赖进程信号/子任务化，放 Phase C 评估）
- 多 worker 并发写同一 SessionStore 文件：桌面默认 json 且按 sessionId 分文件，
  冲突面小；sqlite 路线依赖 busyTimeout 重试
- static-php-cli 编译产物需覆盖全部用到的扩展（curl/json/mbstring/sqlite3/pcntl 视平台）
