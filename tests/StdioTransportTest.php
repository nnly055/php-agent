<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Mcp\StdioTransport;
use PHPUnit\Framework\TestCase;

/**
 * StdioTransport：子进程生命周期、行式 JSON-RPC 收发、通知容忍、超时、env 合并。
 */
final class StdioTransportTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/mcp/fake-stdio-server.php';

    private function makeTransport(array $env = []): StdioTransport
    {
        return new StdioTransport(PHP_BINARY, [self::FIXTURE], $env);
    }

    private function initialize(StdioTransport $t): array
    {
        return $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '0']]], 5);
    }

    public function testInitializeHandshakeIgnoresNotification(): void
    {
        $t = $this->makeTransport();
        try {
            $resp = $this->initialize($t);
            self::assertSame('fake-stdio', $resp['result']['serverInfo']['name']);
            // 夹具在响应前发过一条 notification，recentNotifications 应记录它
            self::assertSame('notifications/tools/list_changed', $t->recentNotifications()[0]['method'] ?? null);
        } finally {
            $t->close();
        }
    }

    public function testToolsCallRoundTrip(): void
    {
        $t = $this->makeTransport();
        try {
            $this->initialize($t);
            $resp = $t->request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                'params' => ['name' => 'echo', 'arguments' => ['text' => '你好 world']], ], 5);
            self::assertSame('echo: 你好 world', $resp['result']['content'][0]['text']);
        } finally {
            $t->close();
        }
    }

    public function testEnvMergedIntoChildProcess(): void
    {
        $t = $this->makeTransport(['MCP_TEST_VAR' => 'abc123']);
        try {
            $this->initialize($t);
            $resp = $t->request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => 'envdump', 'arguments' => []]], 5);
            self::assertSame('env:abc123', $resp['result']['content'][0]['text']);
        } finally {
            $t->close();
        }
    }

    public function testTimeoutThrows(): void
    {
        // 永不响应的子进程
        $t = new StdioTransport(PHP_BINARY, ['-r', 'sleep(30);']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('超时');
        try {
            $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], 1);
        } finally {
            $t->close();
        }
    }

    public function testNonexistentCommandThrowsOnRequest(): void
    {
        // 平台差异：proc_open 对不存在命令，有的环境构造期直接失败、
        // 有的经 sh -c 兜底后请求期 EOF 失败——两个阶段都必须抛 RuntimeException。
        $threw = null;
        try {
            $t = new StdioTransport('definitely-not-a-real-cmd-xyz');
            try {
                $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], 2);
                self::fail('不存在命令必须在构造或请求阶段抛 RuntimeException');
            } finally {
                $t->close();
            }
        } catch (\RuntimeException $e) {
            $threw = $e;
        }
        self::assertNotNull($threw);
    }

    public function testPartialLineStillTimesOut(): void
    {
        // 发出半行后卡死的子进程：阻塞读会在此永久挂起绕过 timeout，非阻塞读由 deadline 抛超时
        $t = new StdioTransport(PHP_BINARY, ['-r', 'fwrite(STDOUT, "{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":"); fflush(STDOUT); sleep(30);']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('超时');
        try {
            $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], 1);
        } finally {
            $t->close();
        }
    }
}
