<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;

/**
 * /mcp：展示每个 server 的状态、传输类型与工具清单。
 *
 * @param array<string, McpServerSpec> $specs
 * @param array<string, McpConnection> $connections
 * @param array<string, string> $failures server 名 => 失败原因
 */
final class McpStatusCommand implements Command
{
    public function __construct(
        private readonly array $specs,
        private readonly array $connections,
        private readonly array $failures,
    ) {
    }

    public function description(): string
    {
        return '查看 MCP server 状态与工具';
    }

    public function execute(array $args, CommandContext $ctx): string
    {
        if ($this->specs === []) {
            return '(未配置 MCP server：在工作区根目录放 .mcp.json，格式见 README)';
        }
        $lines = ['MCP server 状态:'];
        foreach ($this->specs as $name => $spec) {
            $conn = $this->connections[$name] ?? null;
            if ($conn === null) {
                $lines[] = sprintf('  ✗ %-14s [%s] 连接失败: %s', $name, $spec->type, $this->failures[$name] ?? '未知原因');
                continue;
            }
            $toolNames = implode(', ', array_map(
                static fn (McpToolInfo $t) => McpToolAdapter::sanitize($t->name),
                $conn->tools()
            ));
            $lines[] = sprintf(
                '  ✓ %-14s [%s] %d 个工具%s',
                $name,
                $spec->type,
                count($conn->tools()),
                $toolNames !== '' ? ': ' . $toolNames : ''
            );
        }
        return implode("\n", $lines);
    }
}
