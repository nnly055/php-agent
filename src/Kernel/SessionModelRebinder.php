<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Session\SessionStore;

/**
 * 会话切换（/resume、/new）后按会话 meta 重绑模型 client。
 *
 * 语义与 server 侧「未携带 → 已存选择 → 默认档」回退同构：
 *   meta 有选择 → resolve + setClient，返回 selection（主循环随 save 落盘）；
 *   meta 的 profile 已删 → 回落默认档，返回 null（保持旧会话可用，优先于严格报错）；
 *   meta 无选择或会话不在列表（/new 后未保存）→ 默认档，返回 null。
 * 返回 null 即「save 传 null 保留既有 meta」——未做过 /model 选择的会话不被写脏。
 */
final class SessionModelRebinder
{
    /** @return array{profileId:string,model:string}|null */
    public static function rebind(
        ProfileRegistry $registry,
        AgentLoop $loop,
        string $sessionId,
        SessionStore $store,
    ): ?array {
        foreach ($store->list(PHP_INT_MAX) as $m) {
            if ($m->id !== $sessionId) {
                continue;
            }
            if ($m->profileId !== null && $m->profileId !== '') {
                try {
                    $loop->setClient($registry->resolve($m->profileId, $m->model));
                    return $registry->resolvedSelection();
                } catch (\InvalidArgumentException) {
                    // 会话存的 profile 已被删：回落默认档（保持旧会话可用）
                }
            }
            break; // 找到会话但无有效选择 → 落到默认档
        }
        $loop->setClient($registry->resolve(null, null));
        return null;
    }
}
