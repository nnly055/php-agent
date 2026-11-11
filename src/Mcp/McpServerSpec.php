<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * 单个 MCP server 的连接描述（.mcp.json 条目解析结果）。
 * type: 'stdio'（本地子进程）| 'http'（Streamable HTTP）。
 */
final class McpServerSpec
{
    /**
     * @param array $config stdio: ['command'=>string,'args'=>string[],'env'=>array<string,string>]
     *                      http:  ['url'=>string,'headers'=>array<string,string>]
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $config,
        public readonly float $timeout,
    ) {
    }
}
