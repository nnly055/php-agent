<?php

declare(strict_types=1);

namespace PhpAgent\Hook;

/**
 * 生命周期钩子契约（AgentLoop 接线在 Phase 2，接口与注册先就位）。
 *
 * before_* 事件里抛 HookVetoException 即可否决该动作（权限确认、危险命令拦截）。
 */
interface AgentHook
{
    /** @return string[] 关注的事件列表，[] = 全部 */
    public function events(): array;

    public function handle(string $event, array $payload): void;
}
