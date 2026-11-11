<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * server 返回 JSON-RPC error 响应——连接仍然健康，只是这一次调用被拒绝
 * （如未知工具名、参数非法）。与传输失败（\RuntimeException）区分：
 * 仅后者由 McpConnection 标记 dead。
 */
final class McpRpcException extends \RuntimeException
{
}
