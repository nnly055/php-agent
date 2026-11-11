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
        if ($this->specs === [] && $this->failures === []) {
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
        // 配置无效的条目进不了 specs（McpConfig 直接收进 errors），这里补齐展示，避免 /mcp 静默吞掉；
        // 原因已由 McpPlugin 加「配置无效: 」前缀，不重复加
        foreach ($this->failures as $name => $reason) {
            if (!isset($this->specs[$name])) {
                $lines[] = sprintf('  ✗ %-14s %s', $name, str_starts_with($reason, '配置无效') ? $reason : "配置无效: {$reason}");
            }
        }
        return implode("\n", $lines);
    }
}
