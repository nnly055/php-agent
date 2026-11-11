<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\ReadOnlyTool;
use PhpAgent\Mcp\McpConnection;
use PhpAgent\Mcp\McpToolAdapter;
use PhpAgent\Mcp\McpToolInfo;
use PhpAgent\Mcp\Transport;
use PHPUnit\Framework\TestCase;

/**
 * McpToolAdapter：命名 sanitize、schema 兜底、readOnly 透传、execute 走 callTool。
 */
final class McpToolAdapterTest extends TestCase
{
    /** 造一条已完成握手、且下次 tools/call 返回固定文本的连接 */
    private function makeConnection(string $serverName, string $callResult): McpConnection
    {
        $fake = new class($callResult) implements Transport {
            public function __construct(private readonly string $callResult)
            {
            }

            public function request(array $payload, float $timeout): array
            {
                $id = $payload['id'];
                if ($payload['method'] === 'initialize') {
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'serverInfo' => ['name' => 'x', 'version' => '0']]];
                }
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => $this->callResult]]]];
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
        $conn = new McpConnection($serverName, $fake, 5);
        $conn->initializeAndListTools();
        return $conn;
    }

    public function testSanitize(): void
    {
        self::assertSame('my_server-tool_1', McpToolAdapter::sanitize('my server-tool.1'));
        self::assertSame('ok-name_2', McpToolAdapter::sanitize('ok-name_2'));
    }

    public function testAdapterContract(): void
    {
        $conn = $this->makeConnection('my server', $callResult = '工具输出');
        $info = new McpToolInfo('tool.1', '', [], true);
        $adapter = new McpToolAdapter($conn, $info);

        self::assertInstanceOf(ReadOnlyTool::class, $adapter);
        self::assertSame('mcp__my_server__tool_1', $adapter->name());
        self::assertSame('(MCP 工具，无描述)', $adapter->description(), '空描述有兜底文案');
        self::assertSame(['type' => 'object', 'properties' => []], $adapter->schema(), '空 schema 有兜底');
        self::assertTrue($adapter->readOnly());
        self::assertSame($callResult, $adapter->execute(['any' => 'input']));
    }

    public function testAdapterPassthroughSchemaAndDescription(): void
    {
        $conn = $this->makeConnection('srv', 'x');
        $schema = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]];
        $adapter = new McpToolAdapter($conn, new McpToolInfo('grep', '内容搜索', $schema, false));

        self::assertSame('mcp__srv__grep', $adapter->name());
        self::assertSame('内容搜索', $adapter->description());
        self::assertSame($schema, $adapter->schema());
        self::assertFalse($adapter->readOnly());
    }
}
