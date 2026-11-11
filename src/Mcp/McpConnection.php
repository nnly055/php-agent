<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * 单条 MCP 连接的协议层：initialize 握手 → tools/list（分页）→ tools/call。
 * 自增 id 关联响应；传输失败标记 dead 后快速失败（v1 不做运行时重连）。
 */
final class McpConnection
{
    public const PROTOCOL_VERSION = '2025-06-18';
    public const CLIENT_INFO = ['name' => 'php-agent', 'version' => '1.0.0'];

    private int $nextId = 1;
    /** @var 'connecting'|'connected'|'dead' */
    private string $status = 'connecting';
    private string $lastError = '';

    /** @var McpToolInfo[] */
    private array $tools = [];

    public function __construct(
        private readonly string $serverName,
        private readonly Transport $transport,
        private readonly float $timeout,
    ) {
    }

    /** @return McpToolInfo[] 失败抛 \RuntimeException（由 McpPlugin 转为该 server 的失败记录） */
    public function initializeAndListTools(): array
    {
        $resp = $this->rpc('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => self::CLIENT_INFO,
        ]);
        $serverVersion = (string) ($resp['result']['protocolVersion'] ?? self::PROTOCOL_VERSION);
        $this->transport->setProtocolVersion($serverVersion);
        $this->transport->notify(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $tools = [];
        $cursor = null;
        do {
            $params = $cursor !== null ? ['cursor' => $cursor] : [];
            $resp = $this->rpc('tools/list', $params);
            $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
            foreach ($result['tools'] ?? [] as $t) {
                if (!is_array($t) || !isset($t['name']) || !is_string($t['name'])) {
                    continue;
                }
                $tools[] = new McpToolInfo(
                    $t['name'],
                    (string) ($t['description'] ?? ''),
                    is_array($t['inputSchema'] ?? null) ? $t['inputSchema'] : [],
                    is_array($t['annotations'] ?? null) && ($t['annotations']['readOnlyHint'] ?? false) === true,
                );
            }
            $cursor = isset($result['nextCursor']) && is_string($result['nextCursor']) ? $result['nextCursor'] : null;
        } while ($cursor !== null);

        $this->tools = $tools;
        $this->status = 'connected';
        return $tools;
    }

    /**
     * 调用工具并提取文本。isError / 传输失败抛 \RuntimeException
     * （ToolRegistry 统一转 'ERROR: ...' 回传模型）。
     */
    public function callTool(string $toolName, array $arguments): string
    {
        if ($this->status === 'dead') {
            throw new \RuntimeException("MCP server '{$this->serverName}' 已断开（{$this->lastError}），本进程内不再重试");
        }
        try {
            $resp = $this->rpc('tools/call', ['name' => $toolName, 'arguments' => $arguments]);
        } catch (\RuntimeException $e) {
            $this->status = 'dead';
            $this->lastError = $e->getMessage();
            throw $e;
        }
        $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
        $text = self::contentToText($result);
        if (!empty($result['isError'])) {
            throw new \RuntimeException($text !== '' ? $text : "MCP 工具 '{$toolName}' 返回 isError");
        }
        return $text;
    }

    /** content[] → 文本：text 拼接、structuredContent 兜底、非文本块占位 */
    public static function contentToText(array $result): string
    {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        if ($content === [] && isset($result['structuredContent'])) {
            return json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE);
        }
        $parts = [];
        foreach ($content as $block) {
            $type = is_array($block) ? (string) ($block['type'] ?? '') : '';
            if ($type === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            } elseif ($type !== '') {
                $parts[] = "[{$type} 非文本内容]";
            }
        }
        return implode("\n", $parts);
    }

    /** @return McpToolInfo[] */
    public function tools(): array
    {
        return $this->tools;
    }

    public function serverName(): string
    {
        return $this->serverName;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function close(): void
    {
        $this->transport->close();
    }

    private function rpc(string $method, array $params): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method];
        if ($params !== []) {
            $payload['params'] = $params;
        }
        $resp = $this->transport->request($payload, $this->timeout);
        if (isset($resp['error'])) {
            $msg = is_array($resp['error'])
                ? (string) ($resp['error']['message'] ?? json_encode($resp['error'], JSON_UNESCAPED_UNICODE))
                : (string) $resp['error'];
            throw new \RuntimeException("MCP server '{$this->serverName}' 返回错误（{$method}）: {$msg}");
        }
        return $resp;
    }
}
