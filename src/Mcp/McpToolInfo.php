<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * tools/list 返回的单个工具描述。
 */
final class McpToolInfo
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly bool $readOnly,
    ) {
    }
}
