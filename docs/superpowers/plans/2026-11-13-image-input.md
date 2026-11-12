# 图片发送支持 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 用户消息可携带图片（桌面端附件/粘贴），服务端校验后透传给多模态 LLM（openai 兼容 + anthropic），图片随会话历史持久化与回放。

**Architecture:** 归一化消息新增 `image` 块（对齐 Anthropic 块结构）；`/agent` JSON body 加 `images:[{media_type,data}]`（base64 内联）；两个 LLM 客户端各自映射到自家视觉协议；桌面端 Composer 加附件按钮 + 粘贴 + 客户端降采样预处理。前置必修：`bin/server` 显式组装 `RequestBodyBufferMiddleware`（react/http 默认 64KiB 静默丢弃超限 body）。

**Tech Stack:** PHP 8.2 + react/http、PHPUnit；React/TS + Tauri、Vitest。

**Spec:** `docs/superpowers/specs/2026-11-13-image-input-design.md`（本计划从该 spec 推导；执行者两份都要读）

## Global Constraints

- 归一化 image 块形态（verbatim）：`['type' => 'image', 'media_type' => 'image/png', 'data' => '<base64>']`
- 类型白名单（verbatim）：`image/png|image/jpeg|image/webp|image/gif`（小写比较，输入先 lower）
- 单轮 ≤5 张；单图 base64 解码后 ≤ 5 * 1024 * 1024 字节
- `bin/server` 中间件上限：`40 * 1024 * 1024`（40MB）
- compact image 块固定 token 成本：1600/图
- 客户端预处理：长边 >2000px 缩到 2000px；降采样后重编码 JPEG q0.9，仍 >5MB 逐级 0.8/0.7/0.6，仍超抛错
- 用户消息块顺序：image 块在前，text 块在后（图前文后）
- 有图允许 `message` 为空；无图时空 `message` 行为与现状完全一致（CLI 零变化）
- 测试命令：PHP `composer test`（根目录）；桌面 `cd desktop && npm test`；桌面类型检查 `cd desktop && npx tsc -b`

## Review Focus

spec 未显式覆盖、但使用者最容易踩到的输入（每条的钉死测试已并入所属任务）：

1. **粘贴/拖入的内容不是图片**（文本文件、混合 files）→ 非图文件被忽略，不报错不崩溃；合法图片照常进预览。测试：Task 9 步骤 1（paste 非 image MIME 被过滤）。
2. **base64 含非法字符/URL-safe 变体/空白** → 服务端 400，不产生误导性的"message 不能为空"。测试：Task 3 步骤 1（bad base64 → 400）。
3. **超大 body（>40MB）** → 明确 413，而不是静默清空 body 后的 400"message 不能为空"。测试：Task 1 步骤 1（socket 级）。
4. **非视觉模型收到图片** → provider 报错走**既有** error 事件/错误横幅（useAgentStream 现有 catch，Task 8 不改该路径，无新增测试）；Task 11 手工冒烟顺带确认前端不崩溃。
5. **切会话再切回** → 历史里的图片照常回放显示。测试：Task 10 步骤 1（SessionItem toChatMessages 提取 image 块）。

---

### Task 1: bin/server 传输层——请求体上限 64KiB → 40MB

**Files:**
- Modify: `bin/server:69`
- Modify: `bin/server` 顶部 use 行（新增 `React\Http\Middleware\RequestBodyBufferMiddleware`）
- Test: `tests/ServerBodyLimitTest.php`（新建）

**Interfaces:**
- Consumes: 无（首个任务）
- Produces: `/agent` 可接收 ≤40MB 的 JSON body；超限 413。后续所有 images 传输依赖此前提。

- [ ] **Step 1: 写失败测试（真实 socket，`handle()` 级看不到中间件）**

`tests/ServerBodyLimitTest.php`：`proc_open([PHP_BINARY, 'bin/server'], cwd: 项目根, env: AGENT_PROVIDER=fake, AGENT_SESSION_STORE=memory, AGENT_SERVER_PORT=0, AGENT_WORKSPACE=<sys_get_temp_dir() 下临时目录（setUp 创建，tearDown 删除）>)`，从 stdout 抓 `AGENT_LISTENING port=(\d+)` 得端口（模式参照 `HttpTransportTest::startServer` 的就绪轮询，5 秒超时）。用 stream context POST 两个 body：

```php
public function testBodyOverDefaultLimitReachesHandler(): void
{
    // ~200KB 的 message（旧 64KiB 上限会静默清空 body → 误导性 400）
    $body = json_encode(['message' => str_repeat('a', 200 * 1024), 'session_id' => 't1'], JSON_THROW_ON_ERROR);
    [$status] = $this->post('/agent', $body);
    self::assertSame(200, $status); // fake provider 直接给最终回答
}

public function testBodyOverHardLimitReturns413(): void
{
    $body = json_encode(['message' => str_repeat('a', 41 * 1024 * 1024)], JSON_THROW_ON_ERROR);
    [$status] = $this->post('/agent', $body);
    self::assertSame(413, $status);
}
```

`post()` 用 `file_get_contents` + stream context（`method=POST`, `Content-Type: application/json`, `ignore_errors=true`），状态码从 `$http_response_header[0]` 解析。`tearDown` proc_terminate/proc_close。

- [ ] **Step 2: 跑测试确认失败**

Run: `composer test -- --filter ServerBodyLimitTest`
Expected: FAIL——`testBodyOverDefaultLimitReachesHandler` 拿到 400（"message 不能为空"，64KiB 上限吃掉 body）；`testBodyOverHardLimitReturns413` 同样 400 而非 413。

- [ ] **Step 3: 改 bin/server**

```php
$http = new HttpServer(
    Loop::get(),
    new RequestBodyBufferMiddleware(40 * 1024 * 1024),
    $server->handle(...),
);
```

加 use 行 `use React\Http\Middleware\RequestBodyBufferMiddleware;`。注释一行说明：显式中间件把上限从默认 64KiB 提到 40MB（图片 base64 内联），超限由中间件返回 413。

- [ ] **Step 4: 跑测试确认通过**

Run: `composer test -- --filter ServerBodyLimitTest`
Expected: PASS（2 个测试）

- [ ] **Step 5: Commit**

```bash
git add bin/server tests/ServerBodyLimitTest.php
git commit -m "feat: bin/server 显式 RequestBodyBufferMiddleware 40MB（图片 base64 内联前提，超限 413）"
```

---

### Task 2: AgentLoop——normalizeImages + run 可选 $images

**Files:**
- Modify: `src/Agent/AgentLoop.php`（run 签名 + 用户块组装 + 静态校验）
- Test: `tests/AgentLoopTest.php`（追加）

**Interfaces:**
- Consumes: 无
- Produces:
  - `AgentLoop::run(array &$messages, string $userInput, ?\Closure $onEvent = null, array $images = []): string`——`$images` 为 `['media_type'=>string,'data'=>string]` 列表
  - `public static function normalizeImages(array $images): array`——输出 `['type'=>'image','media_type'=>…,'data'=>…]` 块列表；非法抛 `InvalidArgumentException`（消息含具体原因）；幂等（输入已是块形态也可）

- [ ] **Step 1: 写失败测试**

追加到 `tests/AgentLoopTest.php`（FakeLLMClient 单轮脚本，驱动模式照抄 `testLoopExecutesToolAndReturnsFinalText` 的前半段）：

```php
public function testRunBuildsImageBlocksBeforeText(): void
{
    $loop = new AgentLoop(new FakeLLMClient([[StreamEvent::text('ok'), StreamEvent::end('end_turn')]]), new ToolRegistry(), 'sys', 5);
    $messages = [];
    $final = $loop->run($messages, '这是什么', null, [['media_type' => 'image/png', 'data' => base64_encode('abc')]]);
    self::assertSame('ok', $final);
    $content = $messages[0]['content'];
    self::assertSame('image', $content[0]['type']);
    self::assertSame('image/png', $content[0]['media_type']);
    self::assertSame(base64_encode('abc'), $content[0]['data']);
    self::assertSame(['type' => 'text', 'text' => '这是什么'], $content[1]);
}

public function testRunPureImageAllowsEmptyText(): void
{
    // 有图 + 空文本 → 只含 image 块
    $messages = [];
    (new AgentLoop(new FakeLLMClient([[StreamEvent::text('ok'), StreamEvent::end('end_turn')]]), new ToolRegistry(), 'sys', 5))
        ->run($messages, '', null, [['media_type' => 'image/jpeg', 'data' => 'AAAA']]);
    self::assertCount(1, $messages[0]['content']);
    self::assertSame('image', $messages[0]['content'][0]['type']);
}

public function testRunRejectsInvalidImages(): void
{
    $loop = new AgentLoop(new FakeLLMClient([]), new ToolRegistry(), 'sys', 5);
    $messages = [];
    $this->expectException(\InvalidArgumentException::class);
    $loop->run($messages, 'hi', null, [['media_type' => 'image/svg+xml', 'data' => 'AAAA']]); // 类型不在白名单
}
```

再加 `normalizeImages` 直测：6 张 → 抛异常；解码后 >5MB → 抛异常；非 base64（`'!!'`）→ 抛异常；已是块形态输入 → 幂等输出。

- [ ] **Step 2: 跑测试确认失败**

Run: `composer test -- --filter AgentLoopTest`
Expected: FAIL——run 无第 4 参（PHP 多余参数直接忽略，块结构断言失败）；`normalizeImages` 不存在。

- [ ] **Step 3: 实现**

`AgentLoop::run` 第 4 参 `array $images = []`；`normalizeImages` 静态公开：逐项校验 media_type 白名单（lower 后 in_array）、`data` 经 `base64_decode($data, true)` 非 false 且长度 ≤5MB、总数 ≤5，输出块列表。`run` 内：`$content = self::normalizeImages($images);` text 非空时 append text 块；`$images` 与 `$userInput` 均空 → 维持现状（text `''` 块，CLI 兼容）。

- [ ] **Step 4: 跑测试确认通过 + 全量回归**

Run: `composer test`
Expected: 全部 PASS（含既有 AgentLoopTest）

- [ ] **Step 5: Commit**

```bash
git add src/Agent/AgentLoop.php tests/AgentLoopTest.php
git commit -m "feat: AgentLoop 支持用户消息 image 块（normalizeImages 白名单/数量/大小校验）"
```

---

### Task 3: AgentServer——/agent 接收 images 并透传

**Files:**
- Modify: `src/Server/AgentServer.php`（`handleAgent`，约 190-233 行区域）
- Test: `tests/ServerApiTest.php`（追加）

**Interfaces:**
- Consumes: `AgentLoop::normalizeImages(array): array`（Task 2）；`AgentLoop::run(..., array $images = [])`（Task 2）
- Produces: `POST /agent` body 键 `images`（可选，`[{media_type,data}]`）——Task 8 的前端依赖此契约

- [ ] **Step 1: 写失败测试**

追加到 `tests/ServerApiTest.php`（`$this->server()` helper + `ServerRequest` + `Loop::run()` 泵协程的模式照抄 `testAgentPerTurnSwitch`）：

```php
public function testAgentRejectsInvalidImages(): void
{
    $s = $this->server();
    // 类型不在白名单
    $r1 = $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
        'message' => 'hi', 'images' => [['media_type' => 'image/svg+xml', 'data' => 'AAAA']],
    ], JSON_THROW_ON_ERROR)));
    self::assertSame(400, $r1->getStatusCode());
    // 非法 base64
    $r2 = $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
        'message' => 'hi', 'images' => [['media_type' => 'image/png', 'data' => '!!']],
    ], JSON_THROW_ON_ERROR)));
    self::assertSame(400, $r2->getStatusCode());
}

public function testAgentPureImageTurnStoresImageBlocks(): void
{
    $store = new InMemorySessionStore();
    $s = $this->server($store);
    $resp = $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
        'message' => '', 'session_id' => 'img', 'images' => [['media_type' => 'image/png', 'data' => base64_encode('abc')]],
    ], JSON_THROW_ON_ERROR)));
    self::assertSame(200, $resp->getStatusCode());
    Loop::run(); // 泵协程让回合跑完并落盘
    $messages = $store->load('img');
    self::assertSame('image', $messages[0]['content'][0]['type']);
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `composer test -- --filter ServerApiTest`
Expected: FAIL——纯图请求 400（"message 不能为空"）；非法 images 透传无校验。

- [ ] **Step 3: 实现 handleAgent**

`$body['images']` 是数组才取，否则 `[]`；空判定改为 `$message === '' && $images === []` → 400（文案改"message 与 images 不能同时为空"）；`AgentLoop::normalizeImages($images)` 包 try/catch `\InvalidArgumentException` → 400 `$e->getMessage()`；async 闭包里 `run($messages, $message, $onEvent, $images)`。

- [ ] **Step 4: 跑测试确认通过 + 全量回归**

Run: `composer test`
Expected: 全部 PASS

- [ ] **Step 5: Commit**

```bash
git add src/Server/AgentServer.php tests/ServerApiTest.php
git commit -m "feat: /agent 请求体支持 images（服务端校验，纯图放行）"
```

---

### Task 4: AnthropicClient——image 块映射 + Anthropic 夹具

**Files:**
- Modify: `src/LLM/AnthropicClient.php`（`mapMessages` switch 加 case）
- Create: `tests/fixtures/llm/fake-anthropic-server.php`
- Test: `tests/HttpTransportTest.php`（追加，沿用其 LLM 夹具模式）

**Interfaces:**
- Consumes: 归一化 image 块（Global Constraints）
- Produces: Anthropic 协议块 `['type'=>'image','source'=>['type'=>'base64','media_type'=>…,'data'=>…]]`——无下游依赖，仅协议正确性

- [ ] **Step 1: 写失败测试**

`fake-anthropic-server.php`：模式照抄 `fake-openai-server.php`（捕获请求体到 `LLM_CAPTURE_FILE`，回最小 SSE）。响应体（Anthropic Messages 事件序列，`AnthropicClient` 解析器所需的最小集）：

```php
header('Content-Type: text/event-stream');
echo 'data: ' . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text']]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n";
```

测试追加到 `HttpTransportTest.php`（helper `anthropicClient(string $capture): AnthropicClient`——env `AGENT_PROVIDER=anthropic`、`AGENT_BASE_URL=http://127.0.0.1:{$port}`，端口来自 `startServer(self::ANTHROPIC_FIXTURE, ['LLM_CAPTURE_FILE' => $capture])`；`ANTHROPIC_FIXTURE = __DIR__ . '/fixtures/llm/fake-anthropic-server.php'`）：

```php
public function testAnthropicBodyMapsImageBlock(): void
{
    $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
    $client = $this->anthropicClient($capture);
    $messages = [['role' => 'user', 'content' => [
        ['type' => 'image', 'media_type' => 'image/png', 'data' => base64_encode('abc')],
        ['type' => 'text', 'text' => '这是什么'],
    ]]];
    foreach ($client->stream($messages, [], 'sys') as $event) { }
    $body = json_decode((string) file_get_contents($capture), true);
    unlink($capture);
    $block = $body['messages'][0]['content'][0];
    self::assertSame('image', $block['type']);
    self::assertSame(['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('abc')], $block['source']);
    self::assertSame('text', $body['messages'][0]['content'][1]['type']);
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `composer test -- --filter testAnthropicBodyMapsImageBlock`
Expected: FAIL——夹具 404/请求体里没有 image 块（mapMessages switch 不认识 `image`，块被丢弃 → `$block` 断言失败）

- [ ] **Step 3: 实现 mapMessages case**

```php
case 'image':
    $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => (string) $block['media_type'], 'data' => (string) $block['data']]];
    break;
```

- [ ] **Step 4: 跑测试确认通过**

Run: `composer test -- --filter HttpTransportTest`
Expected: PASS（含既有用例）

- [ ] **Step 5: Commit**

```bash
git add src/LLM/AnthropicClient.php tests/fixtures/llm/fake-anthropic-server.php tests/HttpTransportTest.php
git commit -m "feat: AnthropicClient 映射 image 块为 base64 source（补 anthropic 请求体捕获夹具）"
```

---

### Task 5: OpenAICompatClient——含图用户消息 content 变 parts 数组

**Files:**
- Modify: `src/LLM/OpenAICompatClient.php`（`mapMessages` user 分支，约 146-170 行）
- Test: `tests/HttpTransportTest.php`（追加）

**Interfaces:**
- Consumes: 归一化 image 块
- Produces: Chat Completions 视觉 parts——text 块（非空才加）+ `['type'=>'image_url','image_url'=>['url'=>'data:{media_type};base64,{data}']]`；tool_result 回合与无图回合输出形态不变（字符串 content）

- [ ] **Step 1: 写失败测试**

追加到 `HttpTransportTest.php`：

```php
public function testOpenAiBodyMapsImageParts(): void
{
    $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
    $client = $this->openAiClient($capture);
    $messages = [['role' => 'user', 'content' => [
        ['type' => 'image', 'media_type' => 'image/png', 'data' => base64_encode('abc')],
        ['type' => 'text', 'text' => '这是什么'],
    ]]];
    foreach ($client->stream($messages, [], 'sys') as $event) { }
    $body = json_decode((string) file_get_contents($capture), true);
    unlink($capture);
    $content = $body['messages'][1]['content']; // [0] 是 system
    self::assertIsArray($content);
    self::assertSame(['type' => 'text', 'text' => '这是什么'], $content[0]);
    self::assertSame('image_url', $content[1]['type']);
    self::assertSame('data:image/png;base64,' . base64_encode('abc'), $content[1]['image_url']['url']);
}

public function testOpenAiBodyToolResultTurnStaysString(): void
{
    // 回归：tool_result 回合不因 image 改造而变化
    $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
    $client = $this->openAiClient($capture);
    $messages = [
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'run_command', 'input' => ['command' => 'ls']]]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'out']]],
    ];
    foreach ($client->stream($messages, [], 'sys') as $event) { }
    $body = json_decode((string) file_get_contents($capture), true);
    unlink($capture);
    self::assertSame('tool', $body['messages'][2]['role']);
    self::assertSame('out', $body['messages'][2]['content']);
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `composer test -- --filter testOpenAiBody`
Expected: FAIL——image 块被丢弃，content 是字符串 `""`/纯文本而非 parts 数组

- [ ] **Step 3: 实现 mapMessages user 分支**

user 回合：遇 image 块置 `$hasImage = true` 并收集 `image_url` part；text 块仍并入 `$texts`。输出：`$hasImage` 时 content 为 parts 数组（text 非空 → 首 part `['type'=>'text','text'=>implode("\n",$texts)]`，后接 image parts）；否则维持现有字符串形态。tool_result 分支不变（tool results 先 push，parts 有值再 push parts 消息）。

- [ ] **Step 4: 跑测试确认通过 + 全量回归**

Run: `composer test`
Expected: 全部 PASS

- [ ] **Step 5: Commit**

```bash
git add src/LLM/OpenAICompatClient.php tests/HttpTransportTest.php
git commit -m "feat: OpenAICompatClient 含图用户消息映射为 vision parts 数组"
```

---

### Task 6: compact 插件——estimateTokens 与 base64 解耦

**Files:**
- Modify: `plugins/compact/src/Compactor.php`（`estimateTokens`，42-52 行）
- Test: `tests/CompactorTest.php`（追加）

**Interfaces:**
- Consumes: 归一化 image 块
- Produces: `estimateTokens` 对 image 块按固定 1600 token 计；无下游 API 变化

- [ ] **Step 1: 写失败测试**

追加到 `tests/CompactorTest.php`：

```php
public function testEstimateTokensIgnoresBase64Payload(): void
{
    $big = str_repeat('A', 6 * 1024 * 1024); // base64 形态的大图数据
    $messages = [
        ['role' => 'user', 'content' => [
            ['type' => 'image', 'media_type' => 'image/png', 'data' => $big],
            ['type' => 'text', 'text' => '短文本'],
        ]],
    ];
    $t = Compactor::estimateTokens($messages, '');
    // 固定成本 1600 + 短文本估算，远小于 base64 字符数贡献（6MB/2.5 ≈ 250 万 token）
    self::assertLessThan(5000, $t);
    self::assertSame(Compactor::estimateTokens([['role' => 'user', 'content' => [['type' => 'text', 'text' => '短文本']]]], '') + 1600, $t);
}
```

（`use` 行按 CompactorTest 现有引入为准。）

- [ ] **Step 2: 跑测试确认失败**

Run: `composer test -- --filter testEstimateTokensIgnoresBase64Payload`
Expected: FAIL——现状按 json 长度算，估算值 >250 万

- [ ] **Step 3: 实现**

`estimateTokens`：先深拷贝消息并把 image 块削减为 `['type' => 'image']`（剥掉 media_type/data），再按现有 `json_encode + mb_strlen / TOKEN_DIVISOR` 估算，最后 `+ 图片块数 * 1600`。常量 `private const IMAGE_TOKEN_COST = 1600;`。注释说明：base64 是传输形态不代表 token 占用，1600 ≈ 单图实际视觉 token 量级；不剥离会让大图永久越过压缩阈值、工具循环每轮触发多余总结请求。

- [ ] **Step 4: 跑测试确认通过 + 全量回归**

Run: `composer test`
Expected: 全部 PASS（含 compact 既有用例）

- [ ] **Step 5: Commit**

```bash
git add plugins/compact/src/Compactor.php tests/CompactorTest.php
git commit -m "fix: compact estimateTokens 剥离 image base64，按固定 1600 token/图估算"
```

（打包副本由 `desktop/scripts/bundle-backend.sh` 覆盖式刷新，无手动同步步骤。）

---

### Task 7: SessionMeta——纯图首条消息标题回退 `[图片]`

**Files:**
- Modify: `src/Session/SessionMeta.php`（`deriveTitle`，20-35 行）
- Test: `tests/SessionStoreTest.php`（追加）

**Interfaces:**
- Consumes: 归一化 image 块
- Produces: `deriveTitle` 对 image-only 首条用户消息返回 `[图片]`；文本优先级不变

- [ ] **Step 1: 写失败测试**

追加到 `tests/SessionStoreTest.php`：

```php
public function testDeriveTitleFallsBackToImagePlaceholder(): void
{
    $img = ['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/png', 'data' => 'AAAA']]];
    self::assertSame('[图片]', SessionMeta::deriveTitle([$img]));
    // 图文混合：文本优先
    $both = ['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/png', 'data' => 'AAAA'], ['type' => 'text', 'text' => '看这里']]];
    self::assertSame('看这里', SessionMeta::deriveTitle([$both]));
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `composer test -- --filter testDeriveTitleFallsBackToImagePlaceholder`
Expected: FAIL——返回 `(空会话)`

- [ ] **Step 3: 实现 deriveTitle**

外层循环内加 `$hasImage = false`；内层循环遇 `type === 'image'` 置 true；内层循环结束后 `$hasImage` 为 true 即 `return '[图片]'`（文本命中仍走原 return，优先级不变）。

- [ ] **Step 4: 跑测试确认通过**

Run: `composer test -- --filter SessionStoreTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Session/SessionMeta.php tests/SessionStoreTest.php
git commit -m "feat: 纯图首条消息的会话标题回退为 [图片]"
```

---

### Task 8: 桌面管道——api.ts / store / useAgentStream 串起 images

**Files:**
- Modify: `desktop/src/lib/api.ts`（`agentStream`，约 47-58 行）
- Modify: `desktop/src/store/createProjectStore.ts`（`ChatMessage` 类型 + `beginTurn`，约 10-14 / 87-96 行）
- Modify: `desktop/src/hooks/useAgentStream.ts`（`send`，约 9-26 行）
- Test: `desktop/src/hooks/useAgentStream.test.ts`（追加）

**Interfaces:**
- Consumes: `/agent` body 键 `images`（Task 3）
- Produces:
  - `export type ImageAttachment = { mediaType: string; data: string }`（api.ts 导出）
  - `agentStream(baseUrl, message, sessionId, signal?, selection?, images?: ImageAttachment[])`
  - `ChatMessage` 新增可选字段 `images?: ImageAttachment[]`；`beginTurn(text: string, images?: ImageAttachment[])`；`send(text: string, images?: ImageAttachment[])`——Task 9/10 依赖这些签名

- [ ] **Step 1: 写失败测试**

追加到 `useAgentStream.test.ts`（沿用其 mock fetch / store reset 模式）：

```ts
it("send 把 images 写入请求体并进乐观用户气泡", async () => {
  // mock fetch 捕获 body；FakeLLM 流按现有测试的事件 mock 方式
  const img = { mediaType: "image/png", data: "AAAA" };
  await send("hi", [img]);
  const body = JSON.parse((fetch as any).mock.calls[0][1].body);
  expect(body.images).toEqual([{ media_type: "image/png", data: "AAAA" }]);
  const userMsg = useAppStore.getState().messages[0];
  expect(userMsg.images).toEqual([img]);
});
```

（具体 mock 写法以该文件现有用例为准；断言两点：请求体含 snake_case `images`、乐观气泡带 `images` 字段。）

- [ ] **Step 2: 跑测试确认失败**

Run: `cd desktop && npm test -- useAgentStream`
Expected: FAIL——`send` 只收 text，请求体无 `images` 键

- [ ] **Step 3: 实现三个文件**

- api.ts：导出 `ImageAttachment`；`agentStream` 第 6 参 `images?: ImageAttachment[]`，body 加 `...(images?.length ? { images: images.map(i => ({ media_type: i.mediaType, data: i.data })) } : {})`
- createProjectStore.ts：`ChatMessage.images?: ImageAttachment[]`（import from ../lib/api）；`beginTurn(text, images?)` 用户气泡带 `...(images?.length ? { images } : {})`
- useAgentStream.ts：`send(text, images?)` → `beginTurn(text, images)` → `agentStream(..., images)`

- [ ] **Step 4: 跑测试确认通过 + 类型检查**

Run: `cd desktop && npm test -- useAgentStream && npx tsc -b`
Expected: PASS，无类型错误

- [ ] **Step 5: Commit**

```bash
git add desktop/src/lib/api.ts desktop/src/store/createProjectStore.ts desktop/src/hooks/useAgentStream.ts desktop/src/hooks/useAgentStream.test.ts
git commit -m "feat: 桌面管道透传 images（api/store/hook，请求体 snake_case）"
```

---

### Task 9: Composer——附件按钮 + 粘贴 + 预处理 + 缩略图

**Files:**
- Create: `desktop/src/lib/imageFile.ts`
- Modify: `desktop/src/components/chat/Composer.tsx`
- Test: `desktop/src/lib/imageFile.test.ts`（新建）、`desktop/src/components/chat/Composer.test.tsx`（追加）

**Interfaces:**
- Consumes: `send(text, images?)`（Task 8）
- Produces:
  - `imageFile.ts`：`export const MAX_IMAGES = 5; export const MAX_EDGE = 2000; export const MAX_BYTES = 5 * 1024 * 1024;`、`export function nextScale(w: number, h: number): number | null`（长边 >2000 返回缩放比，否则 null）、`export function nextQuality(prev: number | null): number | null`（JPEG 质量档序列 0.9→0.8→0.7→0.6；prev 为 null 给 0.9，耗尽返回 null 表失败）、`export async function fileToAttachment(file: File): Promise<ImageAttachment & { previewUrl: string }>`（canvas 降采样重编码；无法压到 ≤5MB 抛 Error）
  - Composer `onSend` 变为 `(text: string, images: ImageAttachment[]) => void`（恒传数组，空图传 `[]`）——Task 10 不依赖，但 ChatView 的 `send` 直传（Task 8 签名已兼容）

- [ ] **Step 1: 写失败测试**

`imageFile.test.ts`（纯函数部分；canvas 路径由 Composer 测试经 vi.mock 覆盖）：

```ts
describe("nextScale", () => {
  it("长边超 2000 才缩放", () => {
    expect(nextScale(4000, 2000)).toBeCloseTo(0.5);
    expect(nextScale(1999, 1000)).toBeNull();
  });
});
describe("nextQuality", () => {
  it("质量档序列 0.9→0.8→0.7→0.6，耗尽返回 null", () => {
    expect(nextQuality(null)).toBe(0.9);
    expect(nextQuality(0.9)).toBe(0.8);
    expect(nextQuality(0.6)).toBeNull();
  });
});
```

`Composer.test.tsx` 追加（`vi.mock("../../lib/imageFile")` 把 `fileToAttachment` mock 成返回 `{ mediaType: "image/png", data: "AAAA", previewUrl: "data:image/png;base64,AAAA" }`，绕开 jsdom 无 canvas）：

```ts
it("附件按钮选图后出现缩略图，可删除，Enter 发送携带 images", ...) // file input（aria-label="添加图片"）change → 断言缩略图 img 出现；点删除按钮 → 消失；onSend 未被调用（无文本）
it("粘贴图片文件进预览，非图片 MIME 被忽略", ...) // fireEvent.paste(ta, { clipboardData: { files: [pngFile, textFile] } }) → 只有 1 张缩略图
it("纯图可发送（文本为空）", ...) // 有附件无文本时发送按钮可点；onSend("", [{ mediaType: "image/png", data: "AAAA" }])
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd desktop && npm test -- imageFile Composer`
Expected: FAIL——模块/交互不存在

- [ ] **Step 3: 实现**

- `imageFile.ts`：`fileToAttachment` 用 `createImageBitmap(file)` 解码；`nextScale` 判定 → canvas 缩放 → 降采样过则循环 `toBlob('image/jpeg', q)`（q 从 `nextQuality(null)` 起），单次编码 ≤5MB 即停，耗尽抛 `Error('图片过大，请裁剪后重试')`；无需缩放且原始字节 ≤5MB → 原样 `FileReader.readAsDataURL` 剥前缀（PNG/JPEG/WebP/GIF 保留原编码）。`previewUrl` 用 `URL.createObjectURL`（降采样产物）/原文件。
- `Composer.tsx`：`attachments` 状态；隐藏 file input（`accept="image/*"` `multiple`，aria-label="添加图片"）+ `ImagePlus` 图标按钮；`onPaste` 过滤 `clipboardData.files` 中 `type.startsWith('image/')` 且余量内逐个 `fileToAttachment` 异步追加；文本框上方缩略图条（img + 删除按钮 aria-label="移除图片"）；`submit` 改为 `onSend(text.trim(), attachments.map(({mediaType, data}) => ({mediaType, data})))`，发送条件 `text.trim() || attachments.length`，发送后清空；发送按钮 disabled 相应调整；`fileToAttachment` 抛错时 `store.getState().setError(...)`（走现有错误横幅）。`ChatView.tsx` 的 `<Composer onSend={send}>` 无需改（签名兼容，参数多传）。

- [ ] **Step 4: 跑测试确认通过 + 类型检查**

Run: `cd desktop && npm test -- imageFile Composer && npx tsc -b`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add desktop/src/lib/imageFile.ts desktop/src/lib/imageFile.test.ts desktop/src/components/chat/Composer.tsx desktop/src/components/chat/Composer.test.tsx
git commit -m "feat: Composer 附件/粘贴图片，客户端降采样预处理（长边 2000px、≤5MB）"
```

---

### Task 10: 图片呈现——MessageBubble + 会话历史回放

**Files:**
- Modify: `desktop/src/components/chat/MessageBubble.tsx`（user 分支，12-18 行）
- Modify: `desktop/src/components/sessions/SessionItem.tsx`（`toChatMessages`，20-37 行）
- Test: `desktop/src/components/sessions/SessionItem.test.tsx`（追加）

**Interfaces:**
- Consumes: `ChatMessage.images`（Task 8）；归一化 image 块字段 `media_type`/`data`（Task 2）
- Produces: 用户气泡渲染图片缩略图；历史回放同样显示。无下游依赖

- [ ] **Step 1: 写失败测试**

追加到 `SessionItem.test.tsx`（沿用其 mock `loadSession` 的模式）：

```ts
it("历史回放渲染用户消息里的图片", async () => {
  // mock loadSession 返回含 image 块的用户消息
  // 打开会话后断言出现 <img src="data:image/png;base64,AAAA">
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd desktop && npm test -- SessionItem`
Expected: FAIL——image 块被 toChatMessages 丢弃，无 img

- [ ] **Step 3: 实现**

- `toChatMessages` user 分支：`const images = m.content.filter(b => b?.type === "image").map(b => ({ mediaType: b.media_type, data: b.data }))`；`text || images.length` 才 push；push 时 `...(images.length ? { images } : {})`（id 规则不变）
- `MessageBubble` user 分支：`msg.images?.length ? <div className="mb-1.5 flex flex-wrap gap-1.5">{msg.images.map((im, i) => <img key={i} src={`data:${im.mediaType};base64,${im.data}`} alt="用户发送的图片" className="max-h-40 rounded-lg border border-border" />)}</div> : null`，文本照旧

- [ ] **Step 4: 跑测试确认通过 + 全量桌面测试**

Run: `cd desktop && npm test && npx tsc -b`
Expected: 全部 PASS

- [ ] **Step 5: Commit**

```bash
git add desktop/src/components/chat/MessageBubble.tsx desktop/src/components/sessions/SessionItem.tsx desktop/src/components/sessions/SessionItem.test.tsx
git commit -m "feat: 用户气泡与会话历史回放渲染图片"
```

---

### Task 11: 全量验证收尾

**Files:**
- 无新文件；只跑验证

**Interfaces:**
- Consumes: 全部前序任务
- Produces: 验证通过的分支状态

- [ ] **Step 1: PHP 全量测试**

Run: `composer test`
Expected: 全部 PASS

- [ ] **Step 2: 桌面全量测试 + 构建**

Run: `cd desktop && npm test && npx tsc -b && npm run build`
Expected: 全部 PASS，vite build 成功

- [ ] **Step 3: 手工冒烟（离线）**

Run: `AGENT_PROVIDER=fake php bin/server` 起服务，浏览器开内置页或桌面 dev；粘贴一张截图发送。
Expected: SSE 正常回包；`curl http://127.0.0.1:<port>/sessions` 里该会话消息含 `type: "image"` 块；侧栏标题为 `[图片]`（若纯图开场）。

- [ ] **Step 4: 收尾提交（如有零散修正）**

```bash
git status --short   # 确认无遗漏文件
```
