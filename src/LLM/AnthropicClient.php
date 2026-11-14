<?php

declare(strict_types=1);

namespace PhpAgent\LLM;

use PhpAgent\Config;

/**
 * Anthropic Messages API 原生流式客户端。
 * 文档: https://docs.claude.com/en/api/messages
 */
final class AnthropicClient extends BaseSseClient
{
    /** 推理强度 → thinking budget_tokens（max_tokens 不足时自动抬至 budget+1024） */
    private const EFFORT_BUDGET = ['low' => 2048, 'medium' => 8192, 'high' => 16384, 'max' => 32768];

    public function stream(array $messages, array $tools, string $system, array $options = []): \Generator
    {
        $effort = (string) ($options['effort'] ?? 'off');
        $budget = self::EFFORT_BUDGET[$effort] ?? null;
        $maxTokens = $this->config->maxTokens();
        $body = [
            'model' => $this->config->model(),
            'max_tokens' => $maxTokens,
            'stream' => true,
            'system' => $system,
            'messages' => $this->mapMessages($messages, $effort),
            'tools' => $tools, // 内部格式即 Anthropic 形态，直接透传
        ];
        if ($budget !== null) {
            $body['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
            // budget 必须小于 max_tokens：不足则抬高（per-request 覆盖，不动 Config）
            if ($maxTokens <= $budget) {
                $body['max_tokens'] = $budget + 1024;
            }
        }

        $url = $this->config->baseUrl() . '/v1/messages';
        $headers = [
            'x-api-key: ' . $this->config->apiKey(),
            'anthropic-version: 2023-06-01',
        ];

        // index → 块状态（text / tool / thinking）
        $blockKind = [];
        $blockId = [];
        $blockName = [];
        $blockJson = [];
        $blockThinking = [];
        $blockSignature = [];

        $stream = $this->postSse($url, $headers, $body);

        try {
            foreach ($stream as $payload) {
                $j = json_decode($payload, true);
                if (!is_array($j) || !isset($j['type'])) {
                    continue;
                }

                switch ($j['type']) {
                    case 'error':
                        throw new LLMException('Anthropic 流内错误: ' . ($j['error']['message'] ?? $payload));

                    case 'content_block_start':
                        $cb = $j['content_block'] ?? [];
                        $idx = $j['index'];
                        if (($cb['type'] ?? '') === 'tool_use') {
                            $blockKind[$idx] = 'tool';
                            $blockId[$idx] = $cb['id'];
                            $blockName[$idx] = $cb['name'];
                            $blockJson[$idx] = '';
                            yield StreamEvent::toolStart((string) $cb['id'], (string) $cb['name']);
                        } elseif (($cb['type'] ?? '') === 'thinking') {
                            $blockKind[$idx] = 'thinking';
                            $blockThinking[$idx] = '';
                            $blockSignature[$idx] = '';
                        } elseif (($cb['type'] ?? '') === 'redacted_thinking') {
                            // 加密思考块：data 随 start 完整下发，无 delta 流，须原样回传
                            yield StreamEvent::thinkingRedacted((string) ($cb['data'] ?? ''));
                        }
                        break;

                    case 'content_block_delta':
                        $idx = $j['index'];
                        $delta = $j['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            yield StreamEvent::text((string) ($delta['text'] ?? ''));
                        } elseif (($delta['type'] ?? '') === 'input_json_delta' && isset($blockKind[$idx]) && $blockKind[$idx] === 'tool') {
                            $chunk = (string) ($delta['partial_json'] ?? '');
                            $blockJson[$idx] .= $chunk;
                            yield StreamEvent::toolInputDelta($blockId[$idx], $chunk);
                        } elseif (($delta['type'] ?? '') === 'thinking_delta' && ($blockKind[$idx] ?? '') === 'thinking') {
                            $chunk = (string) ($delta['thinking'] ?? '');
                            $blockThinking[$idx] .= $chunk;
                            yield StreamEvent::thinking($chunk);
                        } elseif (($delta['type'] ?? '') === 'signature_delta' && ($blockKind[$idx] ?? '') === 'thinking') {
                            $blockSignature[$idx] .= (string) ($delta['signature'] ?? '');
                        }
                        break;

                    case 'content_block_stop':
                        $idx = $j['index'];
                        if (($blockKind[$idx] ?? '') === 'tool') {
                            $input = json_decode($blockJson[$idx] ?: '{}', true);
                            $input = is_array($input) ? $input : [];
                            yield StreamEvent::toolEnd($blockId[$idx], $blockName[$idx], $input);
                            unset($blockKind[$idx], $blockId[$idx], $blockName[$idx], $blockJson[$idx]);
                        } elseif (($blockKind[$idx] ?? '') === 'thinking') {
                            // 签名完整才发 thinking_end：AgentLoop 只把带签名块入史（无签名回放必 400）
                            if ($blockSignature[$idx] !== '') {
                                yield StreamEvent::thinkingEnd($blockThinking[$idx], $blockSignature[$idx]);
                            }
                            unset($blockKind[$idx], $blockThinking[$idx], $blockSignature[$idx]);
                        }
                        break;

                    case 'message_delta':
                        $stop = $j['delta']['stop_reason'] ?? null;
                        if ($stop !== null) {
                            yield StreamEvent::end((string) $stop);
                        }
                        break;

                    case 'message_stop':
                        return; // 一轮消息结束，finally 会把底层流拉到自然结束
                }
            }
        } finally {
            // 契约：底层流式生成器绝不能挂起中途被销毁（PHP 禁止在生成器销毁上下文切 Fiber）。
            // 无论正常结束、message_stop 提前返回还是中途异常，都把底层流拉到自然再结束。
            try {
                foreach ($stream as $ignored) {
                }
            } catch (\Throwable) {
                // drain 失败不覆盖原异常
            }
        }
    }

    /** 归一化消息 → Anthropic Messages 协议（内部格式与其对齐，主要做清理） */
    private function mapMessages(array $messages, string $effort = 'off'): array
    {
        $out = [];
        foreach ($messages as $msg) {
            $blocks = [];
            foreach ($msg['content'] as $block) {
                switch ($block['type']) {
                    case 'text':
                        if (trim((string) $block['text']) === '') {
                            break; // Anthropic 拒绝空 text 块
                        }
                        $blocks[] = ['type' => 'text', 'text' => $block['text']];
                        break;
                    case 'image':
                        $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => (string) $block['media_type'], 'data' => (string) $block['data']]];
                        break;
                    case 'tool_use':
                        $blocks[] = [
                            'type' => 'tool_use',
                            'id' => $block['id'],
                            'name' => $block['name'],
                            'input' => is_array($block['input']) ? $block['input'] : [],
                        ];
                        break;
                    case 'tool_result':
                        $blocks[] = [
                            'type' => 'tool_result',
                            'tool_use_id' => $block['tool_use_id'],
                            'content' => (string) $block['content'],
                        ];
                        break;
                    case 'thinking':
                    case 'redacted_thinking':
                        // 本轮开 thinking 才回放（off 时带旧块不合法，剥离）；
                        // thinking 块必须签名完整（无签名回放必 400，如 openai 端产出的无签名思考）
                        if ($effort === 'off') {
                            break;
                        }
                        if ($block['type'] === 'thinking') {
                            if ((string) ($block['signature'] ?? '') === '') {
                                break;
                            }
                            $blocks[] = ['type' => 'thinking', 'thinking' => (string) $block['thinking'], 'signature' => (string) $block['signature']];
                        } else {
                            if ((string) ($block['data'] ?? '') === '') {
                                break;
                            }
                            $blocks[] = ['type' => 'redacted_thinking', 'data' => (string) $block['data']];
                        }
                        break;
                }
            }
            if ($blocks !== []) {
                $out[] = ['role' => $msg['role'], 'content' => $blocks];
            }
        }
        return $out;
    }
}
