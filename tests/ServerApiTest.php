<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Agent\ToolRegistry;
use PhpAgent\Config;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\Server\AgentServer;
use PhpAgent\Server\ProfileRegistry;
use PhpAgent\Session\Store\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
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
        $profiles = new ProfileRegistry(); // helper 无 kernel：fake 档 resolve 走 Registry 内置 FakeLLMClient 回退
        $profiles->setProfiles([['id' => 'default', 'name' => 'fake', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['fake']]], 'default');
        return new AgentServer($loop, $store, [], $dir, $config, $profiles);
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

    public function testConfigTestRejectsNonHttpBaseUrl(): void
    {
        $resp = $this->server()->handle(new ServerRequest('POST', 'http://localhost/config/test', [], json_encode([
            'provider' => 'openai', 'api_key' => 'sk-x', 'base_url' => 'file:///etc/passwd',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertStringContainsString('base_url', $body['error']);
    }

    public function testConfigProfilesGetPostRoundTrip(): void
    {
        $s = $this->server();
        $payload = ['profiles' => [
            ['id' => 'p-1', 'name' => 'DS', 'provider' => 'openai', 'baseUrl' => 'https://api.deepseek.com', 'apiKey' => 'sk-x', 'models' => ['deepseek-chat']],
            ['id' => 'p-2', 'name' => 'F', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['fake']],
        ], 'defaultProfile' => 'p-2'];
        $resp = $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertSame(200, $resp->getStatusCode());
        $get = json_decode((string) $s->handle(new ServerRequest('GET', 'http://localhost/config/profiles'))->getBody(), true);
        self::assertSame('p-2', $get['defaultProfile']);
        self::assertCount(2, $get['profiles']);
        self::assertSame('sk-x', $get['profiles'][0]['apiKey'], 'GET 含 key（设置页回显）');
    }

    public function testConfigProfilesRejectsInvalid(): void
    {
        $s = $this->server();
        $resp = $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode([
            'profiles' => [['id' => 'a', 'name' => '', 'provider' => 'nope', 'baseUrl' => '', 'apiKey' => '', 'models' => []]], 'defaultProfile' => 'a',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testAgentPerTurnSwitch(): void
    {
        $store = new InMemorySessionStore();
        $s = $this->server($store);
        $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode([
            'profiles' => [
                ['id' => 'f1', 'name' => 'F1', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['a']],
                ['id' => 'f2', 'name' => 'F2', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['b']],
            ], 'defaultProfile' => 'f1',
        ], JSON_THROW_ON_ERROR)));
        // fake 委托 kernel 单实例 → 两个 profile 解析到同一 fake client，脚本可切换验证调用即达：
        // 这里验证的是链路（不同 profile_id 都被接受并产出 final 事件）；client 区分由 Registry 测试守护
        foreach (['f1', 'f2'] as $pid) {
            $resp = $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
                'message' => 'hi', 'session_id' => 'sw', 'profile_id' => $pid, 'model' => null,
            ], JSON_THROW_ON_ERROR)));
            self::assertSame(200, $resp->getStatusCode());
        }
        // /agent 的 async 协程停在首个 delay() 定时器上：泵一次事件循环让两回合跑完并落盘
        //（与 ConfirmBrokerTest 驱动异步的既有方式一致；不泵则 save 不会执行）
        Loop::run();
        // 选择落盘：最近一轮 f2
        $list = json_decode((string) $s->handle(new ServerRequest('GET', 'http://localhost/sessions'))->getBody(), true);
        $meta = array_values(array_filter($list['sessions'], static fn ($m) => $m['id'] === 'sw'))[0];
        self::assertSame('f2', $meta['profileId']);
    }

    public function testAgentUnknownProfileRejected(): void
    {
        $s = $this->server();
        $resp = $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
            'message' => 'hi', 'session_id' => 'x', 'profile_id' => 'ghost',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testLoadSessionReturnsMeta(): void
    {
        $store = new InMemorySessionStore();
        $s = $this->server($store);
        $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode([
            'profiles' => [['id' => 'p', 'name' => 'P', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['m']]], 'defaultProfile' => 'p',
        ], JSON_THROW_ON_ERROR)));
        $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
            // 携带 profile_id='p'：未传时 selection=null（保留旧值/继承默认档），meta.profileId 落盘为 null，
            // 断言 'p' 永不成立——meta 回传链路需要一个真实选择才能验证（Task 1 契约：null=保留旧值）
            'message' => 'hi', 'session_id' => 'm1', 'profile_id' => 'p',
        ], JSON_THROW_ON_ERROR)));
        // 同上：泵事件循环让 async 协程完成回合并落盘，否则 meta 尚不存在
        Loop::run();
        $load = json_decode((string) $s->handle(new ServerRequest('GET', 'http://localhost/sessions/m1'))->getBody(), true);
        self::assertSame('p', $load['meta']['profileId'] ?? null);
    }
}