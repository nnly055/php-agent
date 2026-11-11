<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * MCP 传输层契约：一条连接的 JSON-RPC 消息收发。
 * 消息即完整的 JSON-RPC 2.0 对象（关联数组）。
 */
interface Transport
{
    /**
     * 发送一条请求（$payload 必须含 'id'），阻塞等待 id 匹配的响应并原样返回。
     * 期间收到的 notification 交给实现方暂存/忽略。失败（超时/连接死）抛 \RuntimeException。
     */
    public function request(array $payload, float $timeout): array;

    /** 发送一条 notification（$payload 不含 'id'），不等待响应。 */
    public function notify(array $payload): void;

    /** initialize 应答后由 McpConnection 回填 server 协议版本；HTTP 传输用于后续请求头，stdio 为 no-op。 */
    public function setProtocolVersion(string $version): void;

    /** 关闭底层连接（stdio 杀子进程）。 */
    public function close(): void;
}
