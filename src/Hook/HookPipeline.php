<?php

declare(strict_types=1);

namespace PhpAgent\Hook;

/**
 * 钩子触发管道：按事件过滤 + 顺序执行。
 * AgentLoop 与入口脚本共用，保证事件语义一致。
 */
final class HookPipeline
{
    /** @param iterable<AgentHook> $hooks */
    public static function fire(iterable $hooks, string $event, array $payload): void
    {
        foreach ($hooks as $hook) {
            $listening = $hook->events();
            if ($listening !== [] && !in_array($event, $listening, true)) {
                continue;
            }
            $hook->handle($event, $payload);
        }
    }
}
