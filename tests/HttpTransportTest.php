<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PhpAgent\LLM\OpenAICompatClient;
use PhpAgent\Mcp\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * HttpTransport：php -S 起本地 Streamable HTTP 夹具，
 * 覆盖 JSON 响应、SSE 响应、Mcp-Session-Id 回传、202 通知、非 2xx 报错。
 * 另沿用同一模式起 OpenAI 兼容夹具，断言 OpenAICompatClient 发出的请求体。
 */
final class HttpTransportTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/mcp/fake-http-server.php';
    private const LLM_FIXTURE = __DIR__ . '/fixtures/llm/fake-openai-server.php';

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
}
