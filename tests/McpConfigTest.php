<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Mcp\McpConfig;
use PHPUnit\Framework\TestCase;

/**
 * .mcp.json 解析：缺失静默、损坏抛异常、stdio/http 形态、非法条目收集、timeout。
 */
final class McpConfigTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/php-agent-mcp-cfg-' . uniqid();
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace . '/.mcp.json');
        @rmdir($this->workspace);
    }

    private function write(string $json): void
    {
        file_put_contents($this->workspace . '/.mcp.json', $json);
    }

    public function testMissingFileIsSilentNoop(): void
    {
        $loaded = McpConfig::load($this->workspace);
        self::assertSame([], $loaded['servers']);
        self::assertSame([], $loaded['errors']);
    }

    public function testCorruptJsonThrows(): void
    {
        $this->write('{ not json');
        $this->expectException(\RuntimeException::class);
        McpConfig::load($this->workspace);
    }

    public function testStdioEntry(): void
    {
        $this->write(json_encode(['mcpServers' => [
            'fs' => ['command' => 'npx', 'args' => ['-y', 'server'], 'env' => ['K' => 'v'], 'timeout' => 5],
        ]]));
        $loaded = McpConfig::load($this->workspace);
        self::assertSame([], $loaded['errors']);
        $spec = $loaded['servers']['fs'];
        self::assertSame('stdio', $spec->type);
        self::assertSame('npx', $spec->config['command']);
        self::assertSame(['-y', 'server'], $spec->config['args']);
        self::assertSame(['K' => 'v'], $spec->config['env']);
        self::assertSame(5.0, $spec->timeout);
    }

    public function testHttpEntryWithDefaultTimeout(): void
    {
        $this->write(json_encode(['mcpServers' => [
            'remote' => ['url' => 'https://mcp.example.com/mcp', 'headers' => ['Authorization' => 'Bearer x']],
        ]]));
        $spec = McpConfig::load($this->workspace)['servers']['remote'];
        self::assertSame('http', $spec->type);
        self::assertSame('https://mcp.example.com/mcp', $spec->config['url']);
        self::assertSame(['Authorization' => 'Bearer x'], $spec->config['headers']);
        self::assertSame(30.0, $spec->timeout, '默认 30 秒');
    }

    public function testInvalidEntriesCollectedAsErrors(): void
    {
        $this->write(json_encode(['mcpServers' => [
            'both' => ['command' => 'x', 'url' => 'https://a'],
            'neither' => ['timeout' => 5],
            'good' => ['command' => 'php'],
        ]]));
        $loaded = McpConfig::load($this->workspace);
        self::assertSame(['command 与 url 必须二选一', 'command 与 url 必须二选一'], array_values($loaded['errors']));
        self::assertArrayHasKey('good', $loaded['servers']);
        self::assertArrayNotHasKey('both', $loaded['servers']);
        self::assertArrayNotHasKey('neither', $loaded['servers']);
    }
}
