# 图片发送支持设计（用户消息携带图片 → 多模态 LLM）

日期：2026-11-13
状态：待确认
关联文档：`docs/superpowers/specs/2026-11-12-multi-provider-design.md`

> builder check 定夺记录（审阅者提出 4 问，处理如下）：① 服务端 64KiB 请求体
> 上限使本特性不可用 → 必须本轮修（见 §1.1，必要前提）；② compact 估算把
> base64 当文本 → 本轮修 `estimateTokens`（见 §1.2）；③ 超大截图 → 客户端
> 降采样重编码优先于硬失败（见 §2）；④ 纯图首条消息会话标题"(空会话)" →
> 回退 `[图片]`（见 §1.3）。

## 背景与目标

现状：`POST /agent` 只收纯文本 `message`；归一化消息块只有
`text / tool_use / tool_result` 三种；两个 LLM 客户端的 `mapMessages`
均不认识 image 块，多模态模型的能力用不上。

目标：

- **桌面端**：聊天输入框支持随消息发送图片——附件按钮选文件 + 直接粘贴剪贴板
  截图（桌面截图场景的主路径），发送前缩略图预览、可删除
- **服务端**：`/agent` 接受图片并透传给多模态模型；openai 兼容与 anthropic
  两类 provider 都支持
- **会话持久化**：图片随归一化历史落盘，多轮对话中模型仍能"看到"此前发过的图；
  历史回放（切会话）时 UI 显示图片

本轮不做（明确排除）：网页聊天页 `public/index.html` 与 CLI 的图片支持
（服务端 API 是通用的，后续加只需前端工作）；工具结果回传图片（如截图工具）；
图片生成；消息内图文混排（图片作为消息整体的一部分，不支持指定位置）。

## 关键决策记录

| 决策点 | 选择 | 理由 |
|---|---|---|
| 归一化块形态 | `['type'=>'image','media_type'=>…,'data'=>base64]` | 代码库约定归一化格式对齐 Anthropic 块结构；与 `media_type` 命名一致 |
| 传输方式 | `/agent` JSON body 新增 `images:[{media_type,data}]`，base64 内联 | 服务仅绑 127.0.0.1 且消息本就走 JSON；不引入 multipart |
| `AgentLoop::run` 签名 | 追加可选参数 `array $images = []`，内部拼进用户消息块 | 签名改动最小；CLI、既有测试等全部调用点零变化 |
| OpenAI 映射 | 含图用户消息 `content` 变 parts 数组（text part + `image_url` data URL） | Chat Completions 视觉协议标准形态；tool_result 回合维持字符串不变 |
| 限制 | 类型白名单 png/jpeg/webp/gif；单轮 ≤5 张；单图 base64 解码后 ≤5MB；服务端校验 | 与 Anthropic 官方上限对齐；防会话 JSON 无限膨胀 |
| 空文本 | 有图时允许 `message` 为空（可发纯图） | 贴图直接问"这是什么"是高频用法 |
| 持久化 | 图片块原样存会话历史 | 多轮协议本就要求每次请求重发图片；compact 压缩只摘要 text 块，被压缩的旧图自然丢弃（可接受） |
| 前端编码 | `FileReader` 读 dataURL 后去前缀，发裸 base64 | 浏览器原生能力，Tauri webview 支持；服务端不解析 data: 前缀 |
| **传输层改造**（必要前提） | `bin/server` 显式组装 middleware 栈，`RequestBodyBufferMiddleware` 上限提至 40MB；超限返回 413 | **现状阻断**：react/http 默认 64KiB 上限，超限 body 被静默清空（连 413 都没有，实测表现为误导性 400"message 不能为空"）——不发图都过不了这条线；5 图 × 5MB 解码 ≈ 34MB base64，40MB 留富余 |
| **compact 估算修正** | `Compactor::estimateTokens` 对 image 块按固定 token 成本计（1600/图），不按 base64 字符数 | 现状按 `json_encode` 长度估算：>~480KB 的图（Retina 截图常态）会被估成 25 万+ token，永久越过压缩阈值，**工具循环每轮都触发一次多余的总结 LLM 调用**；1600 ≈ Anthropic 单图实际 token 量级 |
| **客户端预处理** | 桌面端发送前降采样重编码：长边 >2000px 缩到 2000px；处理后仍 >5MB 再压 JPEG 质量直至达标；仍失败才报错 | macOS Retina 全屏截图普遍 >5MB，硬失败会让主路径不可用；且 Anthropic 服务端本就把大图缩到 ~1568px，客户端预处理不损实际效果、省 token |
| **会话标题** | `SessionMeta::deriveTitle`：首个用户消息含 image 块且无文本 → 标题 `[图片]` | 否则纯图开场（本特性主场景）的会话全部显示"(空会话)" |

## 详细设计

### 1. PHP

**bin/server 传输层改造**（前置，否则本特性整体不可用）：

- 现状：`new HttpServer(Loop::get(), $server->handle(...))`——react/http 对非
  流式 handler 自动包 `RequestBodyBufferMiddleware`，上限硬编码 64KiB，
  超限 body 被**静默丢弃**（handler 收到空 body）
- 改法：显式组装中间件链
  `new HttpServer(Loop::get(), new RequestBodyBufferMiddleware(40 * 1024 * 1024), $server->handle(...))`；
  超限由该中间件返回 413
- 测试须走真实 socket（现有 `HttpTransportTest` 起进程的模式），`handle()`
  级单测看不到中间件

**AgentLoop**（`src/Agent/AgentLoop.php`）：

```php
public function run(
    array &$messages,
    string $userInput,
    ?\Closure $onEvent = null,
    array $images = [],   // 新增：['media_type' => 'image/png', 'data' => '<base64>'] 列表
): string
```

- 用户消息 content = `[image 块…]` +（text 非空时）`['type'=>'text', …]`；
  纯图（text 为空）也合法
- `run` 内对每个 image 块做最终校验（media_type 白名单、data 为合法 base64），
  非法抛 `InvalidArgumentException`

**AgentServer**（`src/Server/AgentServer.php`）：

- `/agent` body 新增可选 `images`：每项 `{media_type, data}`；校验——
  类型 ∈ `image/png|image/jpeg|image/webp|image/gif`、`data` 非空 base64
  且解码后 ≤5MB、单轮 ≤5 张；非法 400（在开 SSE 流之前同步校验，
  失败响应仍是 JSON）
- `message` 为空但 `images` 非空时放行；两者都空仍 400
- 校验后传 `loop->run($messages, $message, $onEvent, $images)`

**AnthropicClient**（`src/LLM/AnthropicClient.php` mapMessages）：image 块 →

```php
['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => …, 'data' => …]]
```

**OpenAICompatClient**（`src/LLM/OpenAICompatClient.php` mapMessages）：
用户回合含 image 块时 `content` 恒为 parts 数组——text 块非空才加
`['type'=>'text','text'=>…]`，image 块 →
`['type'=>'image_url','image_url'=>['url'=>"data:{media_type};base64,{data}"]]`；
tool_result 回合与无图回合维持现有字符串形态不变。

**FakeLLMClient**：脚本驱动，不消费块结构，不改动。

**compact 插件**（`plugins/compact/src/Compactor.php`）：

- `estimateTokens`：遍历块统计时，image 块按固定 1600 token 计，**不**把
  `data` 的 base64 字符数计入 json 长度（把 image 块从 json 串中剔除后再
  估算，外加每图 1600）
- `serializeHistory` 现状已只取 text 块，不变
- 打包副本 `desktop/src-tauri/resources/php-agent/plugins/compact` 同步
  同样的修改（若构建脚本自动复制则确认无遗漏）

### 2. 桌面端（desktop/src）

- **api.ts**：`agentStream(baseUrl, message, sessionId, signal, selection, images?)`，
  body 序列化 `images`；`ChatMessage` 类型加 `images?: {mediaType: string; data: string}[]`
- **Composer.tsx**：
  - 输入行加附件按钮（file input，`accept="image/*"` 可多选）；
    `textarea` 的 `onPaste` 捕获 `clipboardData.files` 中的图片
  - **预处理**（读取阶段做）：解码后长边 >2000px → canvas 缩到 2000px 重编码
    （png 保 png，重编码则转 JPEG q0.9）；处理后 >5MB → 逐级降 JPEG 质量
    重编码至 ≤5MB；全部失败才提示错误。限制数字均以**处理后**为准
  - 本地状态 `images: {mediaType, data, previewUrl}[]`，缩略图条渲染在文本框上方，
    每张带删除按钮；`previewUrl` 仅前端展示用，发送时只带 `mediaType/data`
  - submit：`FileReader.readAsDataURL` → 剥掉 `data:…;base64,` 前缀；
    有图即可发送（文本可空）；发送后清空图片状态
  - 前端同样做 ≤5 张限制与类型过滤（超限提示，与服务端限制一致）
- **useAgentStream / createProjectStore**：`send(text, images)` →
  `beginTurn(text, images)`，乐观插入的用户气泡带图片
- **MessageBubble.tsx**：user 气泡在文本上方渲染图片缩略图（`<img>`，最大高度约束）
- **SessionItem.tsx `toChatMessages`**：user 回合提取 `type === 'image'` 块 →
  `images` 字段，历史回放显示图

### 3. 测试

- **传输层**：真实 socket 测试——>64KiB 的 body 能完整到达 handler（现有
  `HttpTransportTest` 起进程模式）；超 40MB → 413
- **AgentLoopTest**：带 `images` 时用户消息块结构（图前文后、纯图、非法块抛异常）
- **AgentServer 测试**：`/agent` images 校验（类型/数量/大小/空 base64 → 400）；
  合法 images 透传进 loop
- **compact**：`estimateTokens` 对含图历史的估算与 base64 长度解耦
  （大图不触发阈值）；`findSplit` 行为不变
- **SessionMeta**：纯图首条消息 → 标题 `[图片]`
- **客户端映射**：沿用 `HttpTransportTest` 的 fake server 夹具模式
  （`LLM_CAPTURE_FILE` 捕获请求体断言）——Anthropic 夹具如有同类模式同理；
  断言 image 块/ parts 数组的协议形态
- **Composer.test.tsx**：附件添加/删除、Enter 发送携带 images、纯图可发送、
  超 5 张/超限预处理降级路径

## 留给实现者

- Anthropic 侧是否已有请求体捕获夹具；没有则按 OpenAI 侧模式补一个（小事，实现者定）
- Composer 图片读取失败（剪贴板非图、读取中断）的提示形态（实现者按现有 UI 风格定）
- 桌面端缩略图样式细节（沿用现有 design token）
