<?php

declare(strict_types=1);

namespace PhpAgent\Agent;

use PhpAgent\Hook\HookPipeline;
use PhpAgent\Hook\HookVetoException;
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
            // 钩子点：before_llm_request —— payload 以引用携带 messages（可做上下文裁剪/注入）
            // 与 notes（钩子→UI 通知通道，如压缩提示）；fire 是值传参，但引用元素写穿
            $notes = [];
            $hookPayload = [
                'messages' => &$messages,
                'notes' => &$notes,
                'system' => $this->system,
                'iteration' => $iteration,
            ];
            try {
                HookPipeline::fire($this->hooks, 'before_llm_request', $hookPayload);
            } catch (HookVetoException $e) {
                // veto 时故意不发 notes：本轮会被入口回滚，发出『已压缩』类提示会误导用户
                throw new LLMException('本次 LLM 请求被钩子否决: ' . $e->getMessage());
            }
            foreach ((array) $notes as $note) {
                $emit(StreamEvent::notice((string) $note));
            }
            unset($hookPayload, $notes);

            [$text, $assistantBlocks] = $this->oneRound($messages);

            $messages[] = ['role' => 'assistant', 'content' => $assistantBlocks];

            $toolUses = array_values(array_filter(
                $assistantBlocks,
                static fn (array $b) => $b['type'] === 'tool_use'
            ));

            if ($toolUses === []) {
                HookPipeline::fire($this->hooks, 'after_turn', [
                    'messages' => $messages,
                    'text' => $text,
                    'iterations' => $iteration,
                ]);
                return $text !== '' ? $text : '(空回复)';
            }

            $results = [];
            foreach ($toolUses as $use) {
                $input = is_array($use['input'] ?? null) ? $use['input'] : [];

                // 钩子点：before_tool_call —— 抛 HookVetoException 可否决（权限确认/危险拦截）
                try {
                    HookPipeline::fire($this->hooks, 'before_tool_call', ['tool' => $use['name'], 'input' => $input]);
                    $result = $this->registry->execute($use['name'], $input);
                } catch (HookVetoException $e) {
                    $result = 'DENIED: 工具 ' . $use['name'] . ' 被用户拒绝'
                        . ($e->getMessage() !== '' ? '（' . $e->getMessage() . '）' : '');
                }

                // 钩子点：after_tool_result —— 审计、脱敏
                HookPipeline::fire($this->hooks, 'after_tool_result', [
                    'tool' => $use['name'],
                    'input' => $input,
                    'result' => $result,
                ]);

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
     * 注意：消费循环体内绝不抛异常（error 事件、UI 回调异常都先记录、流结束后再抛）——
     * 否则客户端流式生成器会挂起中途被销毁，PHP 禁止在该上下文切 Fiber，无法清理 curl。
     *
     * @return array{0: string, 1: array<int, array>} 文本 + 块列表
     */
    private function oneRound(array $messages): array
    {
        $emit0 = $this->onEvent;
        $emitError = null;
        $emit = static function (StreamEvent $e) use ($emit0, &$emitError): void {
            if ($emitError !== null) {
                return; // 已有异常待抛，静默继续以保证流被拉到底
            }
            try {
                $emit0($e);
            } catch (\Throwable $t) {
                $emitError = $t; // UI 故障不中断流消费，结束后再抛
            }
        };

        $text = '';
        /** @var array<string, array{name: string, json: string}> $openTools */
        $openTools = [];
        /** @var array<int, array> $toolBlocks 按完成顺序收集 tool_use 块 */
        $toolBlocks = [];
        $ended = false;
        $streamError = null;

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
                    $toolBlocks[] = ['type' => 'tool_use', 'id' => $id, 'name' => $entry['name'], 'input' => $input];
                    unset($openTools[$id]);
                    $emit($ev);
                    break;

                case 'message_end':
                    $ended = true;
                    $emit($ev);
                    break;

                case 'error':
                    $streamError = new LLMException($ev->data['message'] ?? '未知流错误');
                    break; // 记录后继续消费，保证流被拉到底
            }
        }

        // 流中断但没收到 message_end：把没收尾的工具也落块，保证协议完整
        foreach ($openTools as $id => $entry) {
            $input = json_decode($entry['json'] ?: '{}', true);
            $toolBlocks[] = ['type' => 'tool_use', 'id' => $id, 'name' => $entry['name'], 'input' => is_array($input) ? $input : []];
        }

        // 流完整结束后，再抛出记录的异常（流内 error 优先，其次 UI 回调异常）
        if ($streamError !== null) {
            throw $streamError;
        }
        if ($emitError !== null) {
            throw $emitError;
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
        private readonly iterable $hooks = [],
    ) {
    }
}
