<?php

declare(strict_types=1);

namespace PhpAgent\LLM;

/**
 * LLM 客户端契约。
 *
 * 消息采用归一化格式（对齐 Anthropic 块结构），由各客户端负责转换成自家协议：
 *   ['role' => 'user',      'content' => [['type' => 'text', 'text' => ...]]]
 *   ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => ...],
 *                                          ['type' => 'tool_use', 'id' => ..., 'name' => ..., 'input' => [...]]]]
 *   ['role' => 'user',      'content' => [['type' => 'tool_result', 'tool_use_id' => ..., 'content' => ...]]]
 *
 * 工具规格采用 Anthropic 形态：[['name', 'description', 'input_schema' => [...]]]
 */
interface LLMClient
{
    /**
     * 发起一次流式对话，逐个 yield StreamEvent。
     *
     * @param array $messages 归一化消息历史（会被客户端转换成协议格式）
     * @param array $tools    工具规格列表
     * @param string $system  系统提示词
     */
    public function stream(array $messages, array $tools, string $system): \Generator;
}
