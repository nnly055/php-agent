<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\Hook\AgentHook;

/**
 * before_llm_request 钩子：估算超阈值则压缩早期历史。
 *
 * 每次压缩事件经工厂重新解析当前生效 client 与 contextTokens（跟随 per-turn
 * 模型切换即时生效，与 CompactCommand 每次执行解析的语义一致）；工厂构造成本
 * 可忽略，惰性语义保留——前置守卫（空历史/失败节流）不通过不调用工厂。
 *
 * notes 引用槽写穿到 AgentLoop → notice 事件 → UI 可见。
 * 失败节流：一次压缩失败后，同任务内（尾条不是任务起点）不再重试，
 * 避免工具循环里每轮迭代重复发起注定失败的请求、notes 刷屏。
 */
final class CompactHook implements AgentHook
{
    private bool $failed = false;

    /** @param \Closure(): Compactor $compactorFactory 每次压缩事件调用（client/contextTokens 运行期解析，不缓存） */
    public function __construct(
        private readonly \Closure $compactorFactory,
    ) {
    }

    public function events(): array
    {
        return ['before_llm_request'];
    }

    public function handle(string $event, array $payload): void
    {
        $messages = &$payload['messages'];
        if (!is_array($messages) || $messages === []) {
            return;
        }

        if ($this->failed) {
            $tail = end($messages);
            if (!is_array($tail) || !Compactor::isTaskStart($tail)) {
                return; // 同任务内节流
            }
            $this->failed = false; // 新任务起点，恢复尝试
        }

        $compactor = ($this->compactorFactory)();
        $system = (string) ($payload['system'] ?? '');
        if (!$compactor->shouldCompact($messages, $system)) {
            return;
        }

        $result = $compactor->compact($messages, $system);
        if ($result->compacted) {
            $payload['notes'][] = $result->note;
        } elseif ($result->note !== '') {
            $this->failed = true;
            $payload['notes'][] = $result->note;
        }
    }
}
