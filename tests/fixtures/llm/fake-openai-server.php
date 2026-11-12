<?php

declare(strict_types=1);

/**
 * 测试夹具：最小 OpenAI /chat/completions 兼容端点。
 * 把收到的请求体原样写入 LLM_CAPTURE_FILE（测试进程经 proc_open env 注入），
 * 再回一段最小 SSE 流：一个文本增量 → finish_reason=stop → [DONE]。
 */
$capture = (string) getenv('LLM_CAPTURE_FILE');
if ($capture !== '') {
    file_put_contents($capture, (string) file_get_contents('php://input'));
}

header('Content-Type: text/event-stream');
$chunk = static fn (array $choice): string => json_encode(
    ['id' => 'chatcmpl-test', 'choices' => [$choice]],
    JSON_UNESCAPED_UNICODE
);
echo 'data: ' . $chunk(['index' => 0, 'delta' => ['content' => 'ok'], 'finish_reason' => null]) . "\n\n";
echo 'data: ' . $chunk(['index' => 0, 'delta' => [], 'finish_reason' => 'stop']) . "\n\n";
echo "data: [DONE]\n\n";
