<?php

declare(strict_types=1);

namespace PhpAgent\LLM;

use PhpAgent\Config;

/**
 * OpenAI Chat Completions 兼容协议的流式客户端。
 * 覆盖：OpenAI、GLM(智谱)、DeepSeek、Kimi(月之暗面)、Qwen(通义) 等所有兼容端点。
 */
final class OpenAICompatClient extends BaseSseClient
{
    public function stream(array $messages, array $tools, string $system): \Generator
    {
        $body = [
            'model' => $this->config->model(),
            'stream' => true,
            'messages' => $this->mapMessages($messages, $system),
            'tools' => array_map(
                static fn (array $t) => [
                    'type' => 'function',
                    'function' => [
                        'name' => $t['name'],
                        'description' => $t['description'],
                        'parameters' => $t['input_schema'],
                    ],
                ],
                $tools
            ),
        ];

        $url = $this->config->baseUrl() . '/chat/completions';
        $headers = ['Authorization: Bearer ' . $this->config->apiKey()];

        // index → 累积中的 tool call。
        // OpenAI 流式协议里，后续 arguments 增量只带 index 不带 id，必须按 index 做 key。
        $openTools = [];
        $ended = false;

        $flushTools = function () use (&$openTools, &$ended): \Generator {
            foreach ($openTools as $t) {
                $input = json_decode($t['args'] ?: '{}', true);
                yield StreamEvent::toolEnd($t['id'], $t['name'], is_array($input) ? $input : []);
            }
            $openTools = [];
            yield StreamEvent::end($ended);
        };

        $stream = $this->postSse($url, $headers, $body);

        try {
            foreach ($stream as $payload) {
                if (trim($payload) === '[DONE]') {
                    if (!$ended) {
                        yield from $flushTools();
                    }
                    return; // finally 会把底层流拉到自然结束
                }

                $j = json_decode($payload, true);
                if (!is_array($j)) {
                    continue;
                }
                if (isset($j['error'])) {
                    throw new LLMException('OpenAI 兼容端点错误: ' . ($j['error']['message'] ?? $payload));
                }

                $choice = $j['choices'][0] ?? null;
                if ($choice === null) {
                    continue; // usage 块等
                }
                $delta = $choice['delta'] ?? [];

                if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
                    yield StreamEvent::thinking((string) $delta['reasoning_content']);
                }
                if (!empty($delta['content'])) {
                    yield StreamEvent::text((string) $delta['content']);
                }

                foreach ($delta['tool_calls'] ?? [] as $tc) {
                    $idx = (int) ($tc['index'] ?? 0);
                    $id = isset($tc['id']) && $tc['id'] !== '' ? (string) $tc['id'] : null;
                    $name = isset($tc['function']['name']) && $tc['function']['name'] !== ''
                        ? (string) $tc['function']['name'] : null;

                    if (!isset($openTools[$idx])) {
                        $openTools[$idx] = [
                            'id' => $id ?? ('call_' . $idx),
                            'name' => $name ?? '',
                            'args' => '',
                        ];
                        yield StreamEvent::toolStart($openTools[$idx]['id'], $openTools[$idx]['name']);
                    } else {
                        if ($id !== null) {
                            $openTools[$idx]['id'] = $id;
                        }
                        if ($name !== null) {
                            $openTools[$idx]['name'] = $name;
                        }
                    }

                    $argsChunk = $tc['function']['arguments'] ?? null;
                    if ($argsChunk !== null && $argsChunk !== '') {
                        $openTools[$idx]['args'] .= (string) $argsChunk;
                        yield StreamEvent::toolInputDelta($openTools[$idx]['id'], (string) $argsChunk);
                    }
                }

                $finish = $choice['finish_reason'] ?? null;
                if ($finish !== null && !$ended) {
                    $ended = (string) $finish;
                    yield from $flushTools();
                }
            }

            // 没等到 [DONE] / finish_reason 也兜底收尾
            if (!$ended) {
                yield from $flushTools();
            }
        } finally {
            // 契约：底层流式生成器绝不能挂起中途被销毁（PHP 禁止在生成器销毁上下文切 Fiber）。
            // 无论正常结束、[DONE] 提前返回还是中途异常，都把底层流拉到自然再结束。
            try {
                foreach ($stream as $ignored) {
                }
            } catch (\Throwable) {
                // drain 失败不覆盖原异常
            }
        }
    }

    /** 归一化消息 → OpenAI Chat Completions 协议 */
    private function mapMessages(array $messages, string $system): array
    {
        $out = [['role' => 'system', 'content' => $system]];

        foreach ($messages as $msg) {
            switch ($msg['role']) {
                case 'user':
                    $texts = [];
                    $toolResults = [];
                    foreach ($msg['content'] as $block) {
                        if ($block['type'] === 'text') {
                            $texts[] = $block['text'];
                        } elseif ($block['type'] === 'tool_result') {
                            $toolResults[] = [
                                'role' => 'tool',
                                'tool_call_id' => $block['tool_use_id'],
                                'content' => (string) $block['content'],
                            ];
                        }
                    }
                    if ($toolResults !== []) {
                        // OpenAI 要求 tool 结果紧跟在带 tool_calls 的 assistant 消息之后
                        array_push($out, ...$toolResults);
                        if ($texts !== [] && trim(implode("\n", $texts)) !== '') {
                            $out[] = ['role' => 'user', 'content' => implode("\n", $texts)];
                        }
                    } else {
                        $out[] = ['role' => 'user', 'content' => implode("\n", $texts)];
                    }
                    break;

                case 'assistant':
                    $entry = ['role' => 'assistant', 'content' => null];
                    $texts = [];
                    foreach ($msg['content'] as $block) {
                        if ($block['type'] === 'text' && trim((string) $block['text']) !== '') {
                            $texts[] = $block['text'];
                        }
                        if ($block['type'] === 'tool_use') {
                            $entry['tool_calls'][] = [
                                'id' => $block['id'],
                                'type' => 'function',
                                'function' => [
                                    'name' => $block['name'],
                                    'arguments' => json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE),
                                ],
                            ];
                        }
                    }
                    if ($texts !== []) {
                        $entry['content'] = implode("\n", $texts);
                    }
                    if (isset($entry['tool_calls']) || $entry['content'] !== null) {
                        $out[] = $entry;
                    }
                    break;
            }
        }

        return $out;
    }
}
