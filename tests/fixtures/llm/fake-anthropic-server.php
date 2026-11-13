<?php

declare(strict_types=1);

/**
 * 测试夹具：最小 Anthropic /v1/messages 端点。
 * 把收到的请求体原样写入 LLM_CAPTURE_FILE（测试进程经 proc_open env 注入），
 * 再回一段最小 SSE 流（AnthropicClient 解析器所需的最小事件序列）：
 * content_block_start → content_block_delta(text_delta) → content_block_stop
 * → message_delta(stop_reason) → message_stop。
 */
$capture = (string) getenv('LLM_CAPTURE_FILE');
if ($capture !== '') {
    file_put_contents($capture, (string) file_get_contents('php://input'));
}

header('Content-Type: text/event-stream');
echo 'data: ' . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text']]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]) . "\n\n";
echo 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n";
