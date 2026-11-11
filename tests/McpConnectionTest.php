<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Mcp\McpConnection;
use PhpAgent\Mcp\Transport;
use PHPUnit\Framework\TestCase;

/**
 * McpConnection：握手顺序、分页、isError、content 提取、断连快速失败。
 * 用 FakeTransport（匿名类）注入 canned 响应。
 */
final class McpConnectionTest extends TestCase
{
    private function makeFake(array $responses): object
    {
        return new class($responses) implements Transport {
            public array $sent = [];
            public array $responses;
            public int $requestCount = 0;
            public string $proto = '';

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function request(array $payload, float $timeout): array
            {
                $this->sent[] = $payload;
                $this->requestCount++;
                $resp = array_shift($this->responses);
                if ($resp === null) {
                    throw new \RuntimeException('夹具响应已耗尽');
                }
                return $resp;
            }

            public function notify(array $payload): void
            {
                $this->sent[] = $payload;
            }

            public function setProtocolVersion(string $version): void
            {
                $this->proto = $version;
            }

            public function close(): void
            {
            }
        };
    }

    private function initResponse(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1,
            'result' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'serverInfo' => ['name' => 'fake', 'version' => '0']]];
    }

    public function testHandshakeOrderAndProtocolFallback(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a', 'description' => '', 'inputSchema' => []]]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $tools = $conn->initializeAndListTools();

        self::assertSame('initialize', $fake->sent[0]['method']);
        self::assertSame('2025-06-18', $fake->sent[0]['params']['protocolVersion']);
        self::assertSame('notifications/initialized', $fake->sent[1]['method']);
        self::assertSame('tools/list', $fake->sent[2]['method']);
        // server 应答 2025-03-26 → 回填传输层协议版本
        self::assertSame('2025-03-26', $fake->proto);
        self::assertCount(1, $tools);
        self::assertSame('connected', $conn->status());
    }

    public function testPaginationFollowsNextCursor(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a', 'description' => '', 'inputSchema' => []]], 'nextCursor' => 'page2']],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => [['name' => 'b', 'description' => '', 'inputSchema' => []]]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $tools = $conn->initializeAndListTools();

        self::assertSame(['a', 'b'], array_map(static fn ($t) => $t->name, $tools));
        self::assertSame('page2', $fake->sent[3]['params']['cursor']);
    }

    public function testPaginationCapAbortsInfiniteCursor(): void
    {
        // 故障/恶意 server 永远回 nextCursor → 100 页上限中止，而不是挂死启动
        $fake = new class implements Transport {
            public int $requestCount = 0;

            public function request(array $payload, float $timeout): array
            {
                $this->requestCount++;
                if (($payload['method'] ?? '') === 'tools/list') {
                    return ['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => [
                        'tools' => [['name' => 'a', 'description' => '', 'inputSchema' => []]],
                        'nextCursor' => 'x',
                    ]];
                }
                return ['jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => [
                    'protocolVersion' => '2025-06-18', 'capabilities' => [],
                    'serverInfo' => ['name' => 'fake', 'version' => '0'],
                ]];
            }

            public function notify(array $payload): void
            {
            }

            public function setProtocolVersion(string $version): void
            {
            }

            public function close(): void
            {
            }
        };
        $conn = new McpConnection('fake', $fake, 5);

        try {
            $conn->initializeAndListTools();
            self::fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('分页超过上限', $e->getMessage());
        }
        // initialize 1 次 + 拉满 100 页 tools/list，第 101 页检测超限中止
        self::assertSame(101, $fake->requestCount);
    }

    public function testReadOnlyHintParsed(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                ['name' => 'ro', 'description' => 'd', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => true]],
                ['name' => 'rw', 'description' => 'd', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => false]],
                ['name' => 'unknown', 'description' => 'd', 'inputSchema' => []],
            ]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $tools = $conn->initializeAndListTools();

        self::assertTrue($tools[0]->readOnly);
        self::assertFalse($tools[1]->readOnly);
        self::assertFalse($tools[2]->readOnly, '未声明注解 = 按需确认');
    }

    public function testCallToolExtractsTextContent(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [
                ['type' => 'text', 'text' => '第一行'],
                ['type' => 'image', 'data' => '...'],
                ['type' => 'text', 'text' => '第二行'],
            ]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        $out = $conn->callTool('x', []);
        self::assertSame("第一行\n[image 非文本内容]\n第二行", $out);
    }

    public function testStructuredContentFallback(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['structuredContent' => ['temp' => 25]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        self::assertSame('{"temp":25}', $conn->callTool('x', []));
    }

    public function testIsErrorThrowsWithToolText(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => '夹具故意失败']], 'isError' => true]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('夹具故意失败');
        $conn->callTool('fail', []);
    }

    public function testTransportFailureMarksDeadAndFastFails(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            // callTool 时抛传输异常
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        try {
            $conn->callTool('x', []);
            self::fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            self::assertSame('dead', $conn->status());
        }
        // 第二次调用快速失败，不再触达 transport
        $count = $fake->requestCount;
        try {
            $conn->callTool('x', []);
            self::fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('已断开', $e->getMessage());
        }
        self::assertSame($count, $fake->requestCount);
    }

    public function testJsonRpcErrorDoesNotMarkDead(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            // server 拒绝这一次调用（如未知工具名）
            ['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => -32602, 'message' => 'Unknown tool']],
            // 重试应成功——连接仍然健康
            ['jsonrpc' => '2.0', 'id' => 4, 'result' => ['content' => [['type' => 'text', 'text' => '重试成功']]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        try {
            $conn->callTool('x', []);
            self::fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Unknown tool', $e->getMessage());
            // server 业务错误 ≠ 连接死亡
            self::assertSame('connected', $conn->status());
        }
        // 重试仍触达 transport 且成功——连接未被误标 dead
        $count = $fake->requestCount;
        self::assertSame('重试成功', $conn->callTool('x', []));
        self::assertSame($count + 1, $fake->requestCount);
    }

    public function testServerErrorMessageSurfaced(): void
    {
        $fake = $this->makeFake([
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32601, 'message' => 'method not found']],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('method not found');
        $conn->initializeAndListTools();
    }
}
