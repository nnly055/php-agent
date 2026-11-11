<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

use PhpAgent\Agent\ReadOnlyTool;
use PhpAgent\Agent\Tool;

/**
 * 把一条 MCP 工具包装成 agent 的 Tool：LLM 以 mcp__<server>__<tool> 调用，
 * execute 透传给所属连接的 tools/call。
 */
final class McpToolAdapter implements Tool, ReadOnlyTool
{
    public function __construct(
        private readonly McpConnection $connection,
        private readonly McpToolInfo $info,
    ) {
    }

    public function name(): string
    {
        return 'mcp__' . self::sanitize($this->connection->serverName()) . '__' . self::sanitize($this->info->name);
    }

    public function description(): string
    {
        return $this->info->description !== '' ? $this->info->description : '(MCP 工具，无描述)';
    }

    public function schema(): array
    {
        return $this->info->inputSchema !== [] ? $this->info->inputSchema : ['type' => 'object', 'properties' => []];
    }

    public function readOnly(): bool
    {
        return $this->info->readOnly;
    }

    public function execute(array $input): string
    {
        return $this->connection->callTool($this->info->name, $input);
    }

    /** 只保留 [A-Za-z0-9_-]，其余替换 _，保证 LLM 侧工具名合法 */
    public static function sanitize(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    }
}
