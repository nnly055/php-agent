<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Command\CommandContext;
use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * McpPlugin 装配：临时目录当项目根（自带 .env），stdio 夹具做真连接，
 * 验证工具注册、真实调用、/mcp 命令、AGENT_MCP=off、损坏配置降级。
 */
final class McpPluginTest extends TestCase
{
    private string $root;
    private string $workspace;

    protected function setUp(): void
    {
        // 临时目录充当项目根：Config 只读这里的 .env，PluginManager 只扫这里的插件，
        // 与开发者本机的真实 .env / plugins/ 完全隔离
        $this->root = sys_get_temp_dir() . '/php-agent-mcp-root-' . uniqid();
        $this->workspace = sys_get_temp_dir() . '/php-agent-mcp-ws-' . uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{.env,.mcp.json}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->workspace . '/.mcp.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        @rmdir($this->workspace);
    }

    private function writeMcpJson(): void
    {
        $fixture = dirname(__DIR__) . '/tests/fixtures/mcp/fake-stdio-server.php';
        file_put_contents($this->workspace . '/.mcp.json', json_encode([
            'mcpServers' => ['fake' => ['command' => PHP_BINARY, 'args' => [$fixture], 'timeout' => 5]],
        ]));
    }

    private function boot(): AgentKernel
    {
        file_put_contents($this->root . '/.env', "AGENT_WORKSPACE={$this->workspace}\n");
        $kernel = new AgentKernel(Config::load($this->root));
        PluginManager::load($kernel, $this->root);
        return $kernel;
    }

    public function testToolsRegisteredAndExecutable(): void
    {
        $this->writeMcpJson();
        $kernel = $this->boot();
        $names = $kernel->toolRegistry()->names();

        self::assertContains('mcp__fake__echo', $names);
        self::assertContains('mcp__fake__ping', $names);
        self::assertContains('mcp__fake__touch', $names);
        self::assertSame('echo: 你好', $kernel->toolRegistry()->execute('mcp__fake__echo', ['text' => '你好']));
    }

    public function testMcpCommandShowsStatus(): void
    {
        $this->writeMcpJson();
        $kernel = $this->boot();
        $cmd = $kernel->command('mcp');
        self::assertNotNull($cmd);
        // CommandContext 的 messages/sessionId 是引用参数，不能传字面量，先落变量
        $messages = [];
        $sessionId = 't';
        $out = $cmd->execute([], new CommandContext($kernel, $messages, $sessionId));
        self::assertStringContainsString('fake', $out);
        self::assertStringContainsString('stdio', $out);
        self::assertStringContainsString('echo', $out);
    }

    public function testDisabledByAgentMcpOff(): void
    {
        $this->writeMcpJson();
        file_put_contents($this->root . '/.env', "AGENT_WORKSPACE={$this->workspace}\nAGENT_MCP=off\n");
        $kernel = new AgentKernel(Config::load($this->root));
        PluginManager::load($kernel, $this->root);

        self::assertNotContains('mcp__fake__echo', $kernel->toolRegistry()->names());
        self::assertNull($kernel->command('mcp'));
    }

    public function testMissingConfigIsSilent(): void
    {
        $kernel = $this->boot(); // workspace 无 .mcp.json
        self::assertNotContains('mcp__fake__echo', $kernel->toolRegistry()->names());
    }

    public function testCorruptConfigSkipsAll(): void
    {
        file_put_contents($this->workspace . '/.mcp.json', '{ broken');
        $kernel = $this->boot(); // 只警告不崩溃
        self::assertNotContains('mcp__fake__echo', $kernel->toolRegistry()->names());
        self::assertSame([], array_values(array_filter(
            $kernel->toolRegistry()->names(),
            static fn (string $n) => str_starts_with($n, 'mcp__')
        )));
    }

    public function testFailingServerSkippedGracefully(): void
    {
        // 启动不了的命令 → 该 server 警告后跳过，其余照常（agent 正常可用）
        file_put_contents($this->workspace . '/.mcp.json', json_encode(['mcpServers' => [
            'dead' => ['command' => 'definitely-not-a-real-cmd-xyz'],
        ]]));
        $kernel = $this->boot();

        self::assertSame([], array_values(array_filter(
            $kernel->toolRegistry()->names(),
            static fn (string $n) => str_starts_with($n, 'mcp__')
        )));
        // CommandContext 的 messages/sessionId 是引用参数，不能传字面量，先落变量
        $messages = [];
        $sessionId = 't';
        $out = $kernel->command('mcp')->execute([], new CommandContext($kernel, $messages, $sessionId));
        self::assertStringContainsString('连接失败', $out);
    }
}
