<?php

declare(strict_types=1);

namespace PhpAgent\Agent;

use PhpAgent\LLM\LLMClient;
use PhpAgent\LLM\LLMException;
use PhpAgent\LLM\StreamEvent;

/**
 * Agent 核心：用户输入 → LLM 流式响应 → 工具调用 → 结果回填 → 再问 LLM，循环直到给出最终回答。
 * 与具体 provider 无关，只消费 StreamEvent、产出归一化消息历史。
 */
final class AgentLoop
{
    /** @var \Closure(StreamEvent): void|null */
    private ?\Closure $onEvent;

    /**
     * @param array $messages 归一化会话历史（引用传递，原地追加）
     * @param \Closure(StreamEvent): void|null $onEvent 每个流式/工具事件都会回调（UI 用）
     * @return string 最终文本回答
     */
    public function run(
        array &$messages,
        string $userInput,
        ?\Closure $onEvent = null,
    ): string {
        $emit = $onEvent ?? static fn (StreamEvent $e) => null;
        $this->onEvent = $emit;

        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $userInput]]];

        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            [$text, $assistantBlocks] = $this->oneRound($messages);

            $messages[] = ['role' => 'assistant', 'content' => $assistantBlocks];

            $toolUses = array_values(array_filter(
                $assistantBlocks,
                static fn (array $b) => $b['type'] === 'tool_use'
            ));

            if ($toolUses === []) {
                return $text !== '' ? $text : '(空回复)';
            }

            $results = [];
            foreach ($toolUses as $use) {
                $result = $this->registry->execute($use['name'], $use['input'] ?? []);
                $emit(StreamEvent::toolResult($use['name'], $result));
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $use['id'],
                    'content' => $result,
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new LLMException(
            "已达到最大工具调用轮数（{$this->maxIterations}），任务中止。可在 .env 调整 AGENT_MAX_ITERATIONS。"
        );
    }

    /**
     * 跑一轮 LLM 流式调用，聚合出 assistant 消息块。
     *
     * @return array{0: string, 1: array<int, array>} 文本 + 块列表
     */
    private function oneRound(array $messages): array
    {
        $emit = $this->onEvent;
        $text = '';
        /** @var array<string, array{name: string, json: string, input?: array}> $openTools */
        $openTools = [];
        /** @var array<int, array> $toolBlocks 按完成顺序收集 tool_use 块 */
        $toolBlocks = [];
        $ended = false;

        $events = $this->client->stream($messages, $this->registry->specs(), $this->system);

        foreach ($events as $ev) {
            switch ($ev->type) {
                case 'text_delta':
                    $text .= $ev->data['text'];
                    $emit($ev);
                    break;

                case 'thinking_delta':
                    $emit($ev); // 思维链只透传给 UI，不进历史
                    break;

                case 'tool_use_start':
                    $openTools[$ev->data['toolId']] = ['name' => $ev->data['toolName'], 'json' => ''];
                    $emit($ev);
                    break;

                case 'tool_input_delta':
                    if (isset($openTools[$ev->data['toolId']])) {
                        $openTools[$ev->data['toolId']]['json'] .= $ev->data['jsonChunk'];
                    }
                    break;

                case 'tool_use_end':
                    $id = $ev->data['toolId'];
                    $entry = $openTools[$id] ?? ['name' => $ev->data['toolName'], 'json' => ''];
                    $input = $ev->data['input'] ?? [];
                    $block = ['type' => 'tool_use', 'id' => $id, 'name' => $entry['name'], 'input' => $input];
                    $toolBlocks[] = $block;
                    unset($openTools[$id]);
                    $emit($ev);
                    break;

                case 'message_end':
                    $ended = true;
                    $emit($ev);
                    break;

                case 'error':
                    throw new LLMException($ev->data['message'] ?? '未知流错误');
            }
        }

        // 流中断但没收到 message_end：把没收尾的工具也落块，保证协议完整
        foreach ($openTools as $id => $entry) {
            $input = json_decode($entry['json'] ?: '{}', true);
            $toolBlocks[] = ['type' => 'tool_use', 'id' => $id, 'name' => $entry['name'], 'input' => is_array($input) ? $input : []];
        }

        $assistantBlocks = [];
        if (trim($text) !== '') {
            $assistantBlocks[] = ['type' => 'text', 'text' => $text];
        }
        array_push($assistantBlocks, ...$toolBlocks);

        if ($assistantBlocks === [] && !$ended) {
            throw new LLMException('LLM 流式响应意外中断，且没有任何内容返回。');
        }

        return [$text, $assistantBlocks];
    }

    public function __construct(
        private readonly LLMClient $client,
        private readonly ToolRegistry $registry,
        private readonly string $system,
        private readonly int $maxIterations = 25,
    ) {
    }
}
