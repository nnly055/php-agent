<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PhpAgent\LLM\AnthropicClient;
use PhpAgent\LLM\OpenAICompatClient;
use PhpAgent\Mcp\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * HttpTransport：php -S 起本地 Streamable HTTP 夹具，
 * 覆盖 JSON 响应、SSE 响应、Mcp-Session-Id 回传、202 通知、非 2xx 报错。
 * 另沿用同一模式起 OpenAI / Anthropic 兼容夹具，断言各客户端发出的请求体。
 */
final class HttpTransportTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/mcp/fake-http-server.php';
    private const LLM_FIXTURE = __DIR__ . '/fixtures/llm/fake-openai-server.php';
    private const ANTHROPIC_FIXTURE = __DIR__ . '/fixtures/llm/fake-anthropic-server.php';

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    private string $port = '';

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            @fclose($this->serverPipes[0]);
            @fclose($this->serverPipes[1]);
            @fclose($this->serverPipes[2]);
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
    }

    /** 启动 php -S，从 stderr 抓取实际端口；$env 额外注入子进程环境变量（默认继承当前环境） */
    private function startServer(string $fixture = self::FIXTURE, array $env = []): string
    {
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:0', $fixture],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env === [] ? null : array_merge(getenv() ?: [], $env)
        );
        if (!is_resource($proc)) {
            $this->fail('无法启动内置 HTTP 服务');
        }
        $this->server = $proc;
        $this->serverPipes = $pipes;
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[2], 8192);
            if (is_string($chunk) && preg_match('/127\.0\.0\.1:(\d+)/', $chunk, $m)) {
                $this->port = $m[1];
                return $this->port;
            }
            usleep(20000);
        }
        $this->fail('内置 HTTP 服务未在 5 秒内监听');
    }

    private function makeTransport(array $headers = []): HttpTransport
    {
        $port = $this->port !== '' ? $this->port : $this->startServer();
        return new HttpTransport("http://127.0.0.1:{$port}/mcp", $headers);
    }

    private function initialize(HttpTransport $t): array
    {
        return $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '0']]], 5);
    }

    public function testInitializeAndJsonResponse(): void
    {
        $t = $this->makeTransport();
        $resp = $this->initialize($t);
        self::assertSame('fake-http', $resp['result']['serverInfo']['name']);
        $t->close();
    }

    public function testNotifyAcceptedWith202(): void
    {
        self::expectNotToPerformAssertions(); // 夹具返回 202；本测试断言"不抛异常"
        $t = $this->makeTransport();
        $this->initialize($t);
        $t->notify(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']); // 202，不应抛异常
        $t->close();
    }

    public function testSessionIdCarriedOnSubsequentRequests(): void
    {
        $t = $this->makeTransport();
        $this->initialize($t); // 夹具下发 sess-test-123
        $resp = $t->request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], 5);
        // 夹具对无会话头的请求返回 404——能拿到工具说明会话头已回传
        self::assertSame('weather', $resp['result']['tools'][0]['name']);
        $t->close();
    }

    public function testSseResponseParsed(): void
    {
        $t = $this->makeTransport();
        $this->initialize($t);
        $resp = $t->request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'weather', 'arguments' => ['city' => '杭州']]], 5);
        self::assertSame('weather(杭州): 晴 25℃', $resp['result']['content'][0]['text']);
        $t->close();
    }

    public function testNon2xxThrows(): void
    {
        $t = $this->makeTransport();
        $this->initialize($t);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        try {
            $t->request(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'boom', 'params' => []], 5);
        } finally {
            $t->close();
        }
    }

    // ── OpenAICompatClient：请求体断言（同一 php -S 模式） ──────────────────

    /** 起 OpenAI 兼容夹具并返回指向它的客户端；夹具把请求体写入 $capture 文件 */
    private function openAiClient(string $capture, array $extraEnv = []): OpenAICompatClient
    {
        $port = $this->startServer(self::LLM_FIXTURE, ['LLM_CAPTURE_FILE' => $capture]);
        $env = array_merge([
            'AGENT_PROVIDER' => 'openai',
            'AGENT_API_KEY' => 'sk-test',
            'AGENT_MODEL' => 'test-model',
            'AGENT_BASE_URL' => "http://127.0.0.1:{$port}",
        ], $extraEnv);
        return new OpenAICompatClient(Config::fromArray($env));
    }

    /** 消费流到自然结束，返回夹具捕获的请求体（assoc 数组） */
    private function consumeStreamAndCapture(OpenAICompatClient $client, string $capture): array
    {
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        foreach ($client->stream($messages, [], '你是测试助手') as $event) {
            // 消费到自然结束
        }
        /** @var array<string, mixed> $body */
        $body = json_decode((string) file_get_contents($capture), true);
        return $body;
    }

    public function testOpenAiBodyIncludesMaxTokensWhenExplicit(): void
    {
        $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
        $client = $this->openAiClient($capture, ['AGENT_MAX_TOKENS' => '2048']);
        $body = $this->consumeStreamAndCapture($client, $capture);
        unlink($capture);

        self::assertSame(2048, $body['max_tokens']);
        self::assertSame('test-model', $body['model']); // 其余字段照常
        self::assertTrue($body['stream']);
    }

    public function testOpenAiBodyOmitsMaxTokensWhenImplicit(): void
    {
        $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
        $client = $this->openAiClient($capture); // 不带 AGENT_MAX_TOKENS
        $body = $this->consumeStreamAndCapture($client, $capture);
        unlink($capture);

        self::assertArrayNotHasKey('max_tokens', $body);
        self::assertSame('test-model', $body['model']); // 其余字段照常
        self::assertTrue($body['stream']);
    }

    // ── AnthropicClient：请求体断言（同一 php -S 模式） ─────────────────────

    /** 起 Anthropic 夹具并返回指向它的客户端；夹具把请求体写入 $capture 文件 */
    private function anthropicClient(string $capture): AnthropicClient
    {
        $port = $this->startServer(self::ANTHROPIC_FIXTURE, ['LLM_CAPTURE_FILE' => $capture]);
        $env = [
            'AGENT_PROVIDER' => 'anthropic',
            'AGENT_API_KEY' => 'sk-test',
            'AGENT_MODEL' => 'test-model',
            'AGENT_BASE_URL' => "http://127.0.0.1:{$port}",
        ];
        return new AnthropicClient(Config::fromArray($env));
    }

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

    // ── 跨协议回放矩阵：会话内切换模型，A 协议产出的历史回放到 B 协议端点 ──────

    /** 捕获 anthropic 夹具收到的请求体 */
    private function captureAnthropic(array $messages): array
    {
        $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
        $client = $this->anthropicClient($capture);
        foreach ($client->stream($messages, [], '你是助手') as $event) { }
        $body = json_decode((string) file_get_contents($capture), true);
        unlink($capture);
        return $body;
    }

    /** 捕获 openai 兼容夹具收到的请求体 */
    private function captureOpenAi(array $messages): array
    {
        $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
        $client = $this->openAiClient($capture);
        foreach ($client->stream($messages, [], '你是助手') as $event) { }
        $body = json_decode((string) file_get_contents($capture), true);
        unlink($capture);
        return $body;
    }

    public function testOpenAiProducedHistoryReplaysToAnthropic(): void
    {
        // OpenAI 客户端产出的归一化历史：assistant 仅 tool_use 块（call_* id）+ tool_result user 消息
        $body = $this->captureAnthropic([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '列出目录']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'call_0', 'name' => 'list_dir', 'input' => ['path' => '.']]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_0', 'content' => 'a.txt']]],
        ]);
        $msgs = $body['messages'];
        self::assertSame('列出目录', $msgs[0]['content'][0]['text']);
        $use = $msgs[1]['content'][0];
        self::assertSame('tool_use', $use['type']);
        self::assertSame('call_0', $use['id'], 'tool id 原样透传不重写');
        $result = $msgs[2]['content'][0];
        self::assertSame('tool_result', $result['type']);
        self::assertSame('call_0', $result['tool_use_id'], 'tool_result 与前一 assistant 的 tool_use 配对');
        // Anthropic 拒收空 text 块：全部 content 无空 text
        foreach ($msgs as $m) {
            foreach ($m['content'] as $b) {
                if (($b['type'] ?? '') === 'text') {
                    self::assertNotSame('', trim((string) $b['text']));
                }
            }
        }
        self::assertSame('你是助手', $body['system'], 'system 在顶层字段');
    }

    public function testAnthropicProducedHistoryReplaysToOpenAi(): void
    {
        // Anthropic 客户端产出的归一化历史：text+tool_use 混合块（toolu_* id）+ tool_result user 消息
        $body = $this->captureOpenAi([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '列出目录']]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => '我来看一下'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_dir', 'input' => ['path' => '.']],
            ]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'a.txt']]],
        ]);
        $msgs = $body['messages'];
        self::assertSame('system', $msgs[0]['role'], 'system 为首条 system 消息');
        $asst = $msgs[2];
        self::assertSame('assistant', $asst['role']);
        self::assertSame('我来看一下', $asst['content'], '文本与 tool_calls 并存时 content 为文本串');
        self::assertSame('toolu_1', $asst['tool_calls'][0]['id'], 'tool id 原样透传不重写');
        self::assertSame('list_dir', $asst['tool_calls'][0]['function']['name']);
        self::assertSame(['path' => '.'], json_decode((string) $asst['tool_calls'][0]['function']['arguments'], true), 'arguments 是合法 JSON 串');
        $tool = $msgs[3];
        self::assertSame('tool', $tool['role'], 'tool_result → role:tool 紧随该 assistant');
        self::assertSame('toolu_1', $tool['tool_call_id']);
        self::assertSame('a.txt', $tool['content']);
    }

    public function testConsecutiveUserMessagesReplayToBothProtocols(): void
    {
        // /skill 注入场景：技能注入消息 + 真实输入 = 连续两条 user 文本消息。
        // 契约 12 只测不改：两侧客户端原样透传（Anthropic 官方 API 自动合并，openai 从不要求交替）；
        // 若未来引入防御性合并，须先修订 spec 并更新本测试（显式决策，不得静默改历史语义）
        $skillMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '已加载技能 php-ext。请遵循指引。']]];
        $realMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '继续任务']]];

        $anthropic = $this->captureAnthropic([$skillMsg, $realMsg]);
        self::assertCount(2, $anthropic['messages']);
        self::assertSame('user', $anthropic['messages'][0]['role']);
        self::assertSame('user', $anthropic['messages'][1]['role']);
        self::assertSame('继续任务', $anthropic['messages'][1]['content'][0]['text']);

        $openai = $this->captureOpenAi([$skillMsg, $realMsg]);
        self::assertSame('user', $openai['messages'][1]['role']); // [0]=system
        self::assertSame('user', $openai['messages'][2]['role']);
        self::assertSame('继续任务', $openai['messages'][2]['content']);
    }

    public function testEmptyAssistantMessageDroppedByBothClients(): void
    {
        // 边界：归一化历史里的 assistant 空 content 数组（流结束但无内容的历史残留）→ 两客户端均整条跳过
        $anthropic = $this->captureAnthropic([
            ['role' => 'assistant', 'content' => []],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '继续']]],
        ]);
        self::assertCount(1, $anthropic['messages']);
        self::assertSame('继续', $anthropic['messages'][0]['content'][0]['text']);

        $openai = $this->captureOpenAi([
            ['role' => 'assistant', 'content' => []],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '继续']]],
        ]);
        self::assertCount(2, $openai['messages']); // [0]=system，[1]=user「继续」
        self::assertSame('继续', $openai['messages'][1]['content']);
    }

    public function testWhitespaceOnlyTextBlockDroppedByAnthropic(): void
    {
        // 边界：仅空白的 text 块 → Anthropic 拒收空 text，映射时丢弃（该消息只剩 tool_result 时不出现坏块）
        $body = $this->captureAnthropic([
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'call_9', 'content' => 'ok'],
                ['type' => 'text', 'text' => '   '],
            ]],
        ]);
        $blocks = $body['messages'][0]['content'];
        self::assertCount(1, $blocks);
        self::assertSame('tool_result', $blocks[0]['type']);
    }

    // ── 推理强度（thinking 块入史）：请求映射、流解析、历史回放/剥离 ────────

    private const THINKING_FIXTURE = __DIR__ . '/fixtures/llm/fake-anthropic-thinking-server.php';

    /** 带 options 消费 Anthropic 流，返回 [事件列表, 捕获的请求体] */
    private function consumeAnthropicWith(array $messages, array $options): array
    {
        $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
        $port = $this->startServer(self::THINKING_FIXTURE, ['LLM_CAPTURE_FILE' => $capture]);
        $client = new AnthropicClient(Config::fromArray([
            'AGENT_PROVIDER' => 'anthropic',
            'AGENT_API_KEY' => 'sk-test',
            'AGENT_MODEL' => 'test-model',
            'AGENT_BASE_URL' => "http://127.0.0.1:{$port}",
        ]));
        $events = [];
        foreach ($client->stream($messages, [], 'sys', $options) as $event) {
            $events[] = $event;
        }
        $body = json_decode((string) file_get_contents($capture), true);
        unlink($capture);
        return [$events, $body];
    }

    private function effortOptions(string $effort): array
    {
        return ['effort' => $effort];
    }

    public function testAnthropicEffortHighSendsBudgetAndRespectsMaxTokens(): void
    {
        [, $body] = $this->consumeAnthropicWith([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $this->effortOptions('high'));
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 16384], $body['thinking']);
        // 默认 max_tokens 8192 ≤ budget → 必须抬到 budget+1024，否则 Anthropic 拒收
        self::assertSame(17408, $body['max_tokens']);
    }

    public function testAnthropicEffortMaxBudgetAndLift(): void
    {
        [, $body] = $this->consumeAnthropicWith([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $this->effortOptions('max'));
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 32768], $body['thinking']);
        self::assertSame(33792, $body['max_tokens']);
    }

    public function testAnthropicEffortOffSendsNoThinking(): void
    {
        [, $body] = $this->consumeAnthropicWith([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $this->effortOptions('off'));
        self::assertArrayNotHasKey('thinking', $body);
        self::assertSame(8192, $body['max_tokens'], 'off 时不抬 max_tokens（现行为不变）');
    }

    /** 历史残留块样例（上一轮带签名思考 + 安全过滤块） */
    private function historyWithThinkingBlocks(): array
    {
        return [
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => '上一轮思考', 'signature' => 'sig-old'],
                ['type' => 'redacted_thinking', 'data' => 'enc-old'],
                ['type' => 'text', 'text' => '上一轮回答'],
            ]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '继续']]],
        ];
    }

    public function testAnthropicOffStripsThinkingBlocks(): void
    {
        // off 时历史残留块同样不合法（Anthropic 要求无 thinking 参数的请求不带块）→ 剥离
        [, $body] = $this->consumeAnthropicWith($this->historyWithThinkingBlocks(), $this->effortOptions('off'));
        foreach ($body['messages'][0]['content'] as $block) {
            self::assertNotSame('thinking', $block['type']);
            self::assertNotSame('redacted_thinking', $block['type']);
        }
        self::assertSame('text', $body['messages'][0]['content'][0]['type']);
        self::assertArrayNotHasKey('thinking', $body);
    }

    public function testAnthropicHighReplaysThinkingBlocksFirst(): void
    {
        [, $body] = $this->consumeAnthropicWith($this->historyWithThinkingBlocks(), $this->effortOptions('high'));
        $blocks = $body['messages'][0]['content'];
        self::assertSame(['type' => 'thinking', 'thinking' => '上一轮思考', 'signature' => 'sig-old'], $blocks[0], 'thinking 块前置回放');
        self::assertSame(['type' => 'redacted_thinking', 'data' => 'enc-old'], $blocks[1]);
        self::assertSame('text', $blocks[2]['type']);
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 16384], $body['thinking']);
    }

    public function testAnthropicThinkingStreamEmitsEndAndRedacted(): void
    {
        [$events,] = $this->consumeAnthropicWith([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $this->effortOptions('high'));
        $kinds = array_map(static fn ($e) => $e->type, $events);
        self::assertContains('thinking_delta', $kinds);
        self::assertContains('thinking_redacted', $kinds);
        $endIdx = (int) array_search('thinking_end', $kinds, true);
        self::assertSame(['text' => '思考A思考B', 'signature' => 'sig-123'], $events[$endIdx]->data);
    }

    // ── openai 兼容端：reasoning_effort 映射 + thinking 块剥离契约 ──────────

    private function captureOpenAiWith(array $messages, array $options): array
    {
        $capture = (string) tempnam(sys_get_temp_dir(), 'php-agent-body-');
        $port = $this->startServer(self::LLM_FIXTURE, ['LLM_CAPTURE_FILE' => $capture]);
        $client = new OpenAICompatClient(Config::fromArray([
            'AGENT_PROVIDER' => 'openai',
            'AGENT_API_KEY' => 'sk-test',
            'AGENT_MODEL' => 'test-model',
            'AGENT_BASE_URL' => "http://127.0.0.1:{$port}",
        ]));
        foreach ($client->stream($messages, [], 'sys', $options) as $event) { }
        $body = json_decode((string) file_get_contents($capture), true);
        unlink($capture);
        return $body;
    }

    public function testOpenAiEffortMaxMapsToHigh(): void
    {
        $body = $this->captureOpenAiWith([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $this->effortOptions('max'));
        self::assertSame('high', $body['reasoning_effort']);
    }

    public function testOpenAiEffortLowPassesThrough(): void
    {
        $body = $this->captureOpenAiWith([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $this->effortOptions('low'));
        self::assertSame('low', $body['reasoning_effort']);
    }

    public function testOpenAiEffortOffOmitsField(): void
    {
        $body = $this->captureOpenAiWith([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], $this->effortOptions('off'));
        self::assertArrayNotHasKey('reasoning_effort', $body);
    }

    public function testOpenAiStripsThinkingBlocks(): void
    {
        // 跨协议回放：anthropic 产出的 thinking/redacted 块对 openai 端无意义 → 剥离，其余块照常
        $body = $this->captureOpenAiWith($this->historyWithThinkingBlocks(), $this->effortOptions('off'));
        $asst = $body['messages'][1];
        self::assertSame('上一轮回答', $asst['content'], 'thinking 块剥离后只剩文本');
        self::assertArrayNotHasKey('reasoning_effort', $body);
    }
}
