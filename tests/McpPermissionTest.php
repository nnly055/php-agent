<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PhpAgent\Hook\ConfirmHook;
use PhpAgent\Hook\HookVetoException;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * MCP × 权限：readOnlyHint 工具自动放行，未声明/声明确认的工具走 ConfirmHook。
 * 真子进程 stdio 夹具全链路验证。
 */
final class McpPermissionTest extends TestCase
{
    private string $root;
    private string $workspace;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php-agent-mcp-perm-root-' . uniqid();
        $this->workspace = sys_get_temp_dir() . '/php-agent-mcp-perm-ws-' . uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.env');
        @unlink($this->workspace . '/.mcp.json');
        @rmdir($this->root);
        @rmdir($this->workspace);
    }

    private function boot(): AgentKernel
    {
        $fixture = dirname(__DIR__) . '/tests/fixtures/mcp/fake-stdio-server.php';
        file_put_contents($this->workspace . '/.mcp.json', json_encode([
            'mcpServers' => ['fake' => ['command' => PHP_BINARY, 'args' => [$fixture], 'timeout' => 5]],
        ]));
        // AGENT_MCP=on 显式覆盖 shell 里可能 export 的 AGENT_MCP=off（.env 优先于 shell 兜底）
        file_put_contents($this->root . '/.env', "AGENT_WORKSPACE={$this->workspace}\nAGENT_MCP=on\n");
        $kernel = new AgentKernel(Config::load($this->root));
        PluginManager::load($kernel, $this->root);
        return $kernel;
    }

    public function testReadOnlyHintToolBypassesConfirmation(): void
    {
        $kernel = $this->boot();
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'y';
        }, $kernel->toolRegistry());

        $hook->handle('before_tool_call', ['tool' => 'mcp__fake__ping', 'input' => []]); // readOnlyHint=true
        self::assertSame([], $calls, 'readOnlyHint 工具不应触发确认');
    }

    public function testDeclaredWriteToolRequiresConfirmation(): void
    {
        $kernel = $this->boot();
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'n';
        }, $kernel->toolRegistry());

        $this->expectException(HookVetoException::class);
        try {
            $hook->handle('before_tool_call', ['tool' => 'mcp__fake__touch', 'input' => []]); // readOnlyHint=false
        } finally {
            self::assertSame(['mcp__fake__touch'], $calls);
        }
    }
}
