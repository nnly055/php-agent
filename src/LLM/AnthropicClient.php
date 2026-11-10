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
    public function stream(array $messages, array $tools, string $system): \Generator
    {
        $body = [
            'model' => $this->config->model(),
            'max_tokens' => $this->config->maxTokens(),
            'stream' => true,
            'system' => $system,
            'messages' => $this->mapMessages($messages),
            'tools' => $tools, // 内部格式即 Anthropic 形态，直接透传
        ];

        $url = $this->config->baseUrl() . '/v1/messages';
        $headers = [
            'x-api-key: ' . $this->config->apiKey(),
            'anthropic-version: 2023-06-01',
        ];

        // index → 块状态（text / tool）
        $blockKind = [];
        $blockId = [];
        $blockName = [];
        $blockJson = [];

        foreach ($this->postSse($url, $headers, $body) as $payload) {
            $j = json_decode($payload, true);
            if (!is_array($j) || !isset($j['type'])) {
                continue;
            }

            switch ($j['type']) {
                case 'error':
                    throw new LLMException('Anthropic 流内错误: ' . ($j['error']['message'] ?? $payload));

                case 'content_block_start':
                    $cb = $j['content_block'] ?? [];
                    if (($cb['type'] ?? '') === 'tool_use') {
                        $idx = $j['index'];
                        $blockKind[$idx] = 'tool';
                        $blockId[$idx] = $cb['id'];
                        $blockName[$idx] = $cb['name'];
                        $blockJson[$idx] = '';
                        yield StreamEvent::toolStart((string) $cb['id'], (string) $cb['name']);
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
                    }
                    break;

                case 'content_block_stop':
                    $idx = $j['index'];
                    if (($blockKind[$idx] ?? '') === 'tool') {
                        $input = json_decode($blockJson[$idx] ?: '{}', true);
                        $input = is_array($input) ? $input : [];
                        yield StreamEvent::toolEnd($blockId[$idx], $blockName[$idx], $input);
                        unset($blockKind[$idx], $blockId[$idx], $blockName[$idx], $blockJson[$idx]);
                    }
                    break;

                case 'message_delta':
                    $stop = $j['delta']['stop_reason'] ?? null;
                    if ($stop !== null) {
                        yield StreamEvent::end((string) $stop);
                    }
                    break;

                case 'message_stop':
                    return; // 一轮消息结束
            }
        }
    }

    /** 归一化消息 → Anthropic Messages 协议（内部格式与其对齐，主要做清理） */
    private function mapMessages(array $messages): array
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
                }
            }
            if ($blocks !== []) {
                $out[] = ['role' => $msg['role'], 'content' => $blocks];
            }
        }
        return $out;
    }
}
