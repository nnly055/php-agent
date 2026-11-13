<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Mcp\HttpTransport;
use PhpAgent\Mcp\McpConfig;
use PhpAgent\Mcp\McpConnection;
use PhpAgent\Mcp\McpServerSpec;
use PhpAgent\Mcp\McpStatusCommand;
use PhpAgent\Mcp\McpToolAdapter;
use PhpAgent\Mcp\StdioTransport;
use PhpAgent\Mcp\Transport;

/**
 * MCP 客户端插件：读全局 ~/.php-agent/mcp.json + <workspace>/.mcp.json（项目同名覆盖全局，
 * 见 McpConfig）→ 逐个连接 → 工具挂进 ToolRegistry。
 * 配置缺失静默跳过；单个 server 失败警告后跳过，不阻塞其余工具。
 * 退出时由 register_shutdown_function 统一关闭连接（杀 stdio 子进程）。
 */
final class McpPlugin implements Plugin
{
    /** @var array<string, McpConnection> */
    private array $connections = [];

    /** @var array<string, string> server 名 => 失败原因 */
    private array $failures = [];

    public function name(): string
    {
        return 'mcp';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        if (!$kernel->config()->mcpEnabled()) {
            return;
        }
        try {
            $loaded = McpConfig::load($kernel->config()->workspace());
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "⚠ MCP 配置解析失败: {$e->getMessage()}（已跳过全部 MCP server）\n");
            return;
        }
        foreach ($loaded['errors'] as $name => $err) {
            fwrite(STDERR, "⚠ MCP server '{$name}' 配置无效: {$err}（已跳过）\n");
            $this->failures[$name] = "配置无效: {$err}";
        }

        foreach ($loaded['servers'] as $spec) {
            $conn = $this->connect($spec);
            if ($conn !== null) {
                $this->connections[$spec->name] = $conn;
            }
        }

        foreach ($this->connections as $conn) {
            foreach ($conn->tools() as $info) {
                $kernel->registerTool(new McpToolAdapter($conn, $info));
            }
        }

        $kernel->registerCommand('mcp', new McpStatusCommand(
            $loaded['servers'],
            $this->connections,
            $this->failures
        ));

        $conns = $this->connections;
        register_shutdown_function(static function () use ($conns): void {
            foreach ($conns as $conn) {
                $conn->close();
            }
        });
    }

    private function connect(McpServerSpec $spec): ?McpConnection
    {
        $transport = null;
        try {
            $transport = match ($spec->type) {
                'stdio' => new StdioTransport(
                    $spec->config['command'],
                    $spec->config['args'],
                    $spec->config['env']
                ),
                'http' => new HttpTransport(
                    $spec->config['url'],
                    $spec->config['headers']
                ),
            };
            $conn = new McpConnection($spec->name, $transport, $spec->timeout);
            $conn->initializeAndListTools();
            return $conn;
        } catch (\Throwable $e) {
            $this->failures[$spec->name] = $e->getMessage();
            if ($transport instanceof Transport) {
                $transport->close();
            }
            fwrite(STDERR, "⚠ MCP server '{$spec->name}' 连接失败: {$e->getMessage()}（已跳过）\n");
            return null;
        }
    }
}
