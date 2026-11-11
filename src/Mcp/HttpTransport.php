<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * Streamable HTTP 传输（MCP 2025-03-26+ 规范）：
 * POST JSON-RPC，响应可能是单条 application/json 或 text/event-stream（SSE），
 * 两者都解析；initialize 后捕获 Mcp-Session-Id 并在后续请求回传。
 * 不做 GET 长连接监听（v1 只消费工具，无 sampling/roots 反调）。
 */
final class HttpTransport implements Transport
{
    private ?string $sessionId = null;
    private string $protocolVersion = '2025-06-18';

    /** @param array<string, string> $headers 附加请求头（来自 .mcp.json 的 headers） */
    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly float $connectTimeout = 15.0,
    ) {
    }

    public function request(array $payload, float $timeout): array
    {
        $status = 0;
        $respHeaders = [];
        $body = '';
        $this->post($payload, $timeout, $status, $respHeaders, $body);
        if ($status === 202) {
            throw new \RuntimeException("MCP HTTP server 对请求返回 202（无响应体）: {$this->url}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("MCP HTTP 请求失败（HTTP {$status}）: {$this->snippet($body)}");
        }
        if (isset($respHeaders['mcp-session-id']) && is_string($respHeaders['mcp-session-id']) && $respHeaders['mcp-session-id'] !== '') {
            $this->sessionId = $respHeaders['mcp-session-id'];
        }
        $contentType = strtolower((string) ($respHeaders['content-type'] ?? ''));
        if (str_contains($contentType, 'text/event-stream')) {
            return $this->fromSse($body, (int) ($payload['id'] ?? -1));
        }
        $msg = json_decode($body, true);
        if (!is_array($msg)) {
            throw new \RuntimeException("MCP HTTP 响应不是合法 JSON: {$this->snippet($body)}");
        }
        return $msg;
    }

    public function notify(array $payload): void
    {
        $status = 0;
        $respHeaders = [];
        $body = '';
        $this->post($payload, 10.0, $status, $respHeaders, $body);
        // 通知不重试不校验：2xx/202 均视为受理，其余静默容忍
    }

    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    public function close(): void
    {
        // HTTP 无长连接需要显式关闭
    }

    /**
     * 阻塞 POST 一次（工具调用在 agent 循环里是同步的，无需 Fiber 流式）。
     * 传引用带回：状态码、响应头（小写键）、响应体。
     */
    private function post(array $payload, float $timeout, int &$status, array &$respHeaders, string &$body): void
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'MCP-Protocol-Version: ' . $this->protocolVersion,
        ];
        if ($this->sessionId !== null) {
            $headers[] = "Mcp-Session-Id: {$this->sessionId}";
        }
        foreach ($this->headers as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }

        $ch = curl_init($this->url);
        if ($ch === false) {
            throw new \RuntimeException("curl 初始化失败: {$this->url}");
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int) ceil($this->connectTimeout),
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException("MCP HTTP 传输错误: {$err}");
        }
        $body = (string) $raw;
    }

    /** 解析 SSE：按空行分事件，拼接 data: 行，返回 id 匹配的消息 */
    private function fromSse(string $body, int $requestId): array
    {
        foreach (preg_split('/\n\s*\n/', trim($body)) ?: [] as $event) {
            $data = '';
            foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data .= trim(substr($line, 5));
                }
            }
            if ($data === '') {
                continue;
            }
            $msg = json_decode($data, true);
            if (is_array($msg) && ($msg['id'] ?? null) === $requestId) {
                return $msg;
            }
        }
        throw new \RuntimeException('MCP HTTP SSE 流中未找到匹配请求 id 的响应');
    }

    private function snippet(string $body): string
    {
        $trimmed = trim($body);
        return $trimmed === '' ? '(空响应体)' : mb_substr($trimmed, 0, 300);
    }
}
