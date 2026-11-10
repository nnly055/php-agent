<?php

declare(strict_types=1);

namespace PhpAgent\Server;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\LLM\StreamEvent;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * 本地 SSE 服务：桌面应用前端的对接面。
 *
 *   GET  /            → 内置极简聊天页（public/index.html）
 *   POST /agent       → SSE 流式对话，body: {"message": "...", "session_id": "..."}
 *                       每条事件: data: {"type": "...", "data": {...}}\n\n
 *
 * Agent 循环在 Fiber 中执行，每个事件 write 后用 delay(0) 让出事件循环刷写 socket，
 * 这样浏览器就能逐 token 收到流式响应。
 */
final class AgentServer
{
    /** @var array<string, array> session_id => 归一化消息历史 */
    private array $sessions = [];

    public function __construct(
        private readonly AgentLoop $loop,
        private readonly string $publicDir,
    ) {
    }

    public function handle(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'GET' && ($path === '/' || $path === '/index.html')) {
            $html = @file_get_contents($this->publicDir . '/index.html');
            if ($html === false) {
                return new Response(500, ['Content-Type' => 'text/plain'], 'index.html 缺失');
            }
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        }

        if ($request->getMethod() === 'POST' && $path === '/agent') {
            return $this->handleAgent($request);
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Not Found');
    }

    private function handleAgent(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return new Response(
                400,
                ['Content-Type' => 'application/json; charset=utf-8'],
                json_encode(['error' => 'message 不能为空'], JSON_UNESCAPED_UNICODE)
            );
        }

        $sid = (string) ($body['session_id'] ?? 'default');
        $messages = &$this->sessions[$sid];
        if (!is_array($messages)) {
            $messages = [];
        }

        $stream = new ThroughStream();

        \React\Async\async(function () use ($stream, &$messages, $message): void {
            try {
                $onEvent = function (StreamEvent $e) use ($stream): void {
                    $stream->write($this->sseFrame($e));
                    \React\Async\delay(0.001); // 让出事件循环，把已写入内容刷给客户端
                };
                $final = $this->loop->run($messages, $message, $onEvent);
                $stream->write($this->sseFrame(StreamEvent::finalAnswer($final)));
                $stream->end();
            } catch (\Throwable $e) {
                $stream->write($this->sseFrame(StreamEvent::error($e->getMessage())));
                $stream->end();
            }
        })();

        return new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], $stream);
    }

    private function sseFrame(StreamEvent $e, ?string $event = null): string
    {
        $line = '';
        if ($event !== null) {
            $line .= "event: {$event}\n";
        }
        $line .= 'data: ' . json_encode(
            ['type' => $e->type, 'data' => $e->data],
            JSON_UNESCAPED_UNICODE
        ) . "\n\n";
        return $line;
    }
}
