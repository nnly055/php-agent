<?php

declare(strict_types=1);

/**
 * 测试夹具：带 thinking 块的 Anthropic /v1/messages 端点。
 * 把收到的请求体原样写入 LLM_CAPTURE_FILE（测试进程经 proc_open env 注入），
 * 回一段含思考块的最小 SSE 流：
 * thinking 块（thinking_delta×2 + signature_delta）→ redacted_thinking 块（data 随 start 下发）
 * → text 块 → message_delta(stop_reason) → message_stop。
 */
$capture = (string) getenv('LLM_CAPTURE_FILE');
if ($capture !== '') {
    file_put_contents($capture, (string) file_get_contents('php://input'));
}

header('Content-Type: text/event-stream');
$frame = static fn (array $j): string => 'data: ' . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n\n";
echo $frame(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking']]);
echo $frame(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => '思考A']]);
echo $frame(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => '思考B']]);
echo $frame(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-123']]);
echo $frame(['type' => 'content_block_stop', 'index' => 0]);
echo $frame(['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'redacted_thinking', 'data' => 'enc-data']]);
echo $frame(['type' => 'content_block_stop', 'index' => 1]);
echo $frame(['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'text']]);
echo $frame(['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]);
echo $frame(['type' => 'content_block_stop', 'index' => 2]);
echo $frame(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]);
echo $frame(['type' => 'message_stop']);
