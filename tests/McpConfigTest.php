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
        @unlink($this->workspace . '-global.json');
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

    // ── 全局层（~/.php-agent/mcp.json）：项目同名整条覆盖 ──

    private function writeGlobal(string $json): string
    {
        $global = $this->workspace . '-global.json';
        file_put_contents($global, $json);
        return $global;
    }

    public function testGlobalFileLoaded(): void
    {
        $global = $this->writeGlobal(json_encode(['mcpServers' => ['g' => ['command' => 'php']]]));
        $loaded = McpConfig::load($this->workspace, $global);
        self::assertSame([], $loaded['errors']);
        self::assertSame('php', $loaded['servers']['g']->config['command']);
    }

    public function testProjectOverridesSameNameGlobal(): void
    {
        $global = $this->writeGlobal(json_encode(['mcpServers' => ['fs' => ['command' => 'old', 'args' => ['a']]]]));
        $this->write(json_encode(['mcpServers' => ['fs' => ['command' => 'new']]]));
        $spec = McpConfig::load($this->workspace, $global)['servers']['fs'];
        self::assertSame('new', $spec->config['command'], '整条覆盖，不保留全局 args');
        self::assertSame([], $spec->config['args']);
    }

    public function testGlobalAndProjectMerged(): void
    {
        $global = $this->writeGlobal(json_encode(['mcpServers' => ['g' => ['command' => 'php']]]));
        $this->write(json_encode(['mcpServers' => ['p' => ['url' => 'https://a']]]));
        $loaded = McpConfig::load($this->workspace, $global);
        self::assertSame(['g', 'p'], array_keys($loaded['servers']));
    }

    public function testCorruptGlobalThrows(): void
    {
        $global = $this->writeGlobal('{ not json');
        try {
            McpConfig::load($this->workspace, $global);
            self::fail('全局 JSON 损坏必须抛异常');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($global, $e->getMessage(), '消息须标注来源文件');
        }
    }

    public function testInvalidProjectEntryShadowsValidGlobal(): void
    {
        $global = $this->writeGlobal(json_encode(['mcpServers' => ['x' => ['command' => 'php']]]));
        $this->write(json_encode(['mcpServers' => ['x' => ['command' => 'a', 'url' => 'https://b']]]));
        $loaded = McpConfig::load($this->workspace, $global);
        self::assertArrayHasKey('x', $loaded['errors'], '项目非法条目进 errors');
        self::assertArrayNotHasKey('x', $loaded['servers'], '不回落到全局合法条目');
    }
}
