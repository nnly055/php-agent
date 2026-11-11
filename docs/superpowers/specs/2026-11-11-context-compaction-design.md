# 上下文压缩（compact 插件）设计文档

日期：2026-11-11　|　状态：已与用户逐节确认（§1–§5 全部认可）

## 背景与目标

会话历史无限增长会撞上模型上下文窗口。对标 Claude Code 的 `/compact` 机制：
当估算 token 超过阈值时，用当前 LLM 把早期历史总结成一条摘要替换原历史，
保留最近一轮任务原文。同时提供 `/compact` 手动触发。

本项目已预留接入点：`AgentLoop` 的 `before_llm_request` 钩子以引用携带
`messages`（`src/Agent/AgentLoop.php:38-45`，注释原文"可做上下文裁剪/注入"）。
本功能以独立插件实现，成为该钩子点的参考实现（与 diff 插件之于工具装饰器、
MCP 插件之于 provider 扩展并列）。

已确认的两个关键决策：

- **压缩策略**：LLM 摘要压缩（非无 LLM 截断）
- **触发方式**：自动阈值 + `/compact` 手动命令

被否决的备选方案：压缩逻辑内置 AgentLoop（核心变厚、不可停用）；
核心抽象 ContextManager 接口（`before_llm_request` 引用传递已是该抽象，YAGNI）。

## §1 总体架构与数据流

新增 `plugins/compact/`，与 `plugins/diff/` 同构：`plugin.php` 引导返回类名 +
`src/` 类文件；重命名 `plugin.php.disabled` 即停用（与 diff 一致，不加独立开关）。

| 文件 | 职责 |
|---|---|
| `plugins/compact/plugin.php` | 引导，返回插件类名 |
| `plugins/compact/src/CompactPlugin.php` | 实现 `Plugin`：注册钩子 + `/compact` 命令 |
| `plugins/compact/src/CompactHook.php` | 实现 `AgentHook`，监听 `before_llm_request`（薄壳：估算 → 超限则委托压缩 → 写回 notes） |
| `plugins/compact/src/Compactor.php` | 核心算法：token 估算、找压缩边界、构造总结请求、生成替换历史 |
| `plugins/compact/src/CompactCommand.php` | 实现 `Command`，`/compact` 手动触发 |

数据流（自动路径）：

1. 每轮 LLM 请求前，`AgentLoop` fire `before_llm_request`，payload 以引用携带 `messages`
2. `CompactHook` 调 `Compactor::estimateTokens()` 估算；
   ≥ `AGENT_CONTEXT_TOKENS × AGENT_COMPACT_THRESHOLD` 时触发压缩
3. `Compactor` 经 `AgentKernel::provider($config->provider())` 惰性取得 LLM 客户端，
   发起**无工具**（specs 为空数组）的总结请求
4. 替换历史写回 `$payload['messages']`——PHP 数组引用元素语义，
   写穿到 `AgentLoop::$messages`，本轮请求、`after_turn` 落盘、`/resume`
   读到的都是压缩后历史，**会话存储层零改动**
5. 压缩动作经 notes 通道（§3）以 `notice` 事件通知 UI

手动路径：`/compact` 经 `CommandContext`（以引用持有 `messages`，可经
`kernel()` 取 provider）走同一 `Compactor`，就地替换，返回结果文本。

## §2 压缩算法

### token 估算

纯 PHP 无 tokenizer，用保守启发式（宁可早压缩）：
`mb_strlen(json_encode($messages, JSON_UNESCAPED_UNICODE))` 总字符数 ÷ 2.5
（中英混合经验值），system 提示文本单独计入。公开为
`Compactor::estimateTokens(array $messages, string $system): int`，测试覆盖。

### 压缩边界

归一化历史中，普通 `user` 文本消息是任务起点；`tool_result` 消息虽为 user
角色但块带 `tool_use_id`，可据此区分。

- 从尾向前找**最后一个任务起点** `splitIndex`，压缩 `[0, splitIndex)`，
  当前任务全程原样保留
- 天然保证：不切开 `tool_use ↔ tool_result` 配对；不产生无配对的 tool 块；
  满足 Anthropic 首条必须是 user 的约束（保留段以任务起点 user 开头）
- `splitIndex === 0`（只有一个任务）→ 无可压缩内容，不压缩
- 手动 `/compact` 与自动阈值用同一边界规则，不引入第二档深度（YAGNI）

### 替换形态（协议安全）

Anthropic 要求 user/assistant 严格交替；保留段以 user 开头，摘要若也是单独
user 消息会连续两条 user。因此替换为**合成对**：

```
[user: "<context-summary>\n{摘要正文}\n</context-summary>\n\n以上是早期对话
        的压缩摘要，请基于它继续当前任务。"]
[assistant: "已了解上述摘要，继续处理当前任务。"]   ← 短确认，恢复交替
+ 原历史 [splitIndex..] 原样保留
```

摘要正文由总结请求产出，总结提示词要求结构化输出：任务目标 / 关键决定 /
文件改动 / 未完成事项 / 用户约束与偏好。

### 失败语义与幂等

- best-effort：总结请求抛异常（无 key、网络断、provider 不可用）→
  **历史原样保留**，notes 通知"压缩失败，已保留原历史"，绝不破坏现场
- 失败节流：失败后在本任务内不重试——`CompactHook` 记住失败状态，
  仅当历史末尾再次出现普通 `user` 文本消息（= 新任务起点）时才恢复尝试，
  避免工具循环内每轮迭代重复发起注定失败的请求、notes 刷屏；
  手动 `/compact` 不受节流限制
- 幂等：旧摘要消息会进入下次总结输入，被自然再压缩，无需特殊处理

## §3 核心小增强：钩子 → UI 通知通道

钩子拿不到 `onEvent` 闭包，自动压缩必须让用户可见。利用现有引用机制加一条
通用通道（核心改动 ~10 行，任何钩子未来均可复用）：

1. `src/Agent/AgentLoop.php:39` 处：fire 前建 `$notes = []`，
   payload 增加 `'notes' => &$notes` 引用槽——钩子追加
   `$payload['notes'][] = '...'` 可写穿（与 messages 同机制）
2. fire 后 `foreach ($notes as $n) $emit(StreamEvent::notice($n));`
3. `src/LLM/StreamEvent.php` 增加 `notice(string $text)` 静态构造
   （type = `notice`，data = `{text}`），docblock 补一行事件说明
4. `src/Ui/ConsoleUi.php` 事件 switch 增加 `notice` case（暗色单行输出）
5. `src/Server/AgentServer.php` SSE 全事件透传（`sseFrame` 通用序列化），
   零改动；`public/index.html` 事件 switch 增加可选 `notice` case
   （未知类型本就静默落空，不加也不出错）

## §4 配置与文档

`.env.example` 与 `src/Config.php` 新增两个配置（沿用现有 `$this->env[...]` 模式）：

```bash
AGENT_CONTEXT_TOKENS=128000     # 模型上下文窗口大小（按所用模型调整）
AGENT_COMPACT_THRESHOLD=0.8     # 估算达到窗口 80% 时自动压缩
```

- `Config::contextTokens(): int`、`Config::compactThreshold(): float`（0–1，越界回落默认）
- README 增加 compact 小节（写法对标现有"权限模式与审计"段落）：
  机制说明、两个配置项、`/compact` 命令、停用方式
- `docs/plugin-dev-guide.md` 将 compact 标注为 `before_llm_request` +
  `registerCommand` 双扩展点的参考实现

## §5 测试与验收

新增 `tests/CompactorTest.php`（离线，`FakeLLMClient` 或桩客户端注入）：

1. 估算：字符数→token 映射合理、随历史增长单调递增
2. 边界：多任务历史恰好切在最后任务起点；含 `tool_use/tool_result` 配对的
   历史压缩后配对完整；单任务（splitIndex=0）不压缩
3. 替换：结果首条为 user、user/assistant 交替、无孤立 tool 块
4. 写穿：hook/命令路径修改经引用可观察
5. 失败：客户端抛异常 → 历史原样返回且不抛出
6. 命令：`/compact` 强制压缩生效；短历史返回"无需压缩"提示

`tests/AgentLoopTest.php` 补一例：钩子写 notes → `run()` 产出 `notice` 事件。

验收：`vendor/bin/phpunit` 全绿；`php bin/agent --fake` 下 `/compact` 走通
（fake provider 可注入固定摘要文本，无 key 环境也能演示交互）。

## 范围外（明确不做）

- 无 LLM 的截断/占位符式裁剪（已否决）
- 保留最近 K 轮可配置（固定保留最后任务，需要时再加）
- 分层压缩、压缩历史单独持久化、跨会话摘要复用
- 精确 tokenizer 接入（bpe/tiktoken PHP 移植）
