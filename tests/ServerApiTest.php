<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Agent\ToolRegistry;
use PhpAgent\Config;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\Server\AgentServer;
use PhpAgent\Session\Store\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class ServerApiTest extends TestCase
{
    private function server(?InMemorySessionStore $store = null, ?Config $config = null): AgentServer
    {
        $store ??= new InMemorySessionStore();
        $loop = new AgentLoop(new FakeLLMClient([]), new ToolRegistry(), 'sys', 5);
        $dir = sys_get_temp_dir() . '/php-agent-srv-' . uniqid();
        mkdir($dir, 0777, true);
        if ($config === null) {
            file_put_contents($dir . '/.env', "AGENT_PROVIDER=fake\nAGENT_SESSION_STORE=memory\n");
            $config = Config::load($dir);
        }
        return new AgentServer($loop, $store, [], $dir, $config);
    }

    public function testStatusReturnsFieldsAndCors(): void
    {
        $resp = $this->server()->handle(new ServerRequest('GET', 'http://localhost/status'));
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('fake', $body['provider']);
        self::assertSame('memory', $body['sessionStore']);
        self::assertSame(Config::VERSION, $body['version']);
        self::assertArrayHasKey('workspace', $body);
        self::assertArrayHasKey('permissions', $body);
        self::assertArrayHasKey('mcp', $body);
    }

    public function testOptionsPreflightReturns204(): void
    {
        $resp = $this->server()->handle(new ServerRequest('OPTIONS', 'http://localhost/agent'));
        self::assertSame(204, $resp->getStatusCode());
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $resp->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testSessionsListLoadDelete(): void
    {
        $store = new InMemorySessionStore();
        $store->save('s1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => '你好']]]]);
        $server = $this->server($store);

        $list = json_decode((string) $server->handle(new ServerRequest('GET', 'http://localhost/sessions'))->getBody(), true);
        self::assertCount(1, $list['sessions']);
        self::assertSame('s1', $list['sessions'][0]['id']);

        $load = json_decode((string) $server->handle(new ServerRequest('GET', 'http://localhost/sessions/s1'))->getBody(), true);
        self::assertSame('s1', $load['id']);
        self::assertCount(1, $load['messages']);

        $missing = json_decode((string) $server->handle(new ServerRequest('GET', 'http://localhost/sessions/nope'))->getBody(), true);
        self::assertSame([], $missing['messages']);

        $del = $server->handle(new ServerRequest('DELETE', 'http://localhost/sessions/s1'));
        self::assertSame(200, $del->getStatusCode());
        self::assertSame([], $store->load('s1'));
    }

    public function testUnknownRoute404WithCors(): void
    {
        $resp = $this->server()->handle(new ServerRequest('GET', 'http://localhost/nope'));
        self::assertSame(404, $resp->getStatusCode());
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testStatusExceptionReturns500WithCors(): void
    {
        // AGENT_WORKSPACE 指向不存在的目录：Config::workspace() 在 /status 时惰性抛异常
        $dir = sys_get_temp_dir() . '/php-agent-srv-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/.env', "AGENT_PROVIDER=fake\nAGENT_SESSION_STORE=memory\nAGENT_WORKSPACE=/nonexistent-" . uniqid() . "\n");
        try {
            $server = $this->server(null, Config::load($dir));
            $resp = $server->handle(new ServerRequest('GET', 'http://localhost/status'));
            self::assertSame(500, $resp->getStatusCode());
            self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
            $body = json_decode((string) $resp->getBody(), true);
            self::assertArrayHasKey('error', $body);
        } finally {
            @unlink($dir . '/.env');
            @rmdir($dir);
        }
    }

    public function testConfigTestFakeProviderReturnsOk(): void
    {
        $resp = $this->server()->handle(new ServerRequest('POST', 'http://localhost/config/test', [], json_encode([
            'provider' => 'fake', 'api_key' => '', 'base_url' => '', 'model' => '',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('latency_ms', $body);
        self::assertArrayHasKey('message', $body);
    }

    public function testConfigTestRejectsUnknownProvider(): void
    {
        $resp = $this->server()->handle(new ServerRequest('POST', 'http://localhost/config/test', [], json_encode([
            'provider' => 'nope',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertStringContainsStringIgnoringCase('provider', $body['error']);
    }

    public function testConfigTestOpenAiWithoutModelFailsFast(): void
    {
        $resp = $this->server()->handle(new ServerRequest('POST', 'http://localhost/config/test', [], json_encode([
            'provider' => 'openai', 'api_key' => 'sk-x',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertStringContainsString('模型', $body['message']);
    }
}