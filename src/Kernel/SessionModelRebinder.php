<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Session\SessionStore;

/**
 * 会话切换（/resume、/new）后按会话 meta 重绑模型 client。
 *
 * 语义与 server 侧「未携带 → 已存选择 → 默认档」回退同构：
 *   meta 有 profile 选择 → resolve + bind，返回三键 selection（effort 来自 meta）；
 *   meta 仅存 effort（effort-only 会话）→ client 回落默认档，强度照常恢复；
 *   meta 的 profile 已删 → 回落默认档，保持旧会话可用，优先于严格报错；
 *   meta 无任何选择或会话不在列表（/new 后未保存）→ 默认档，返回 null。
 * 返回 null 即「save 传 null 保留既有 meta」——未做过选择的会话不被写脏。
 */
final class SessionModelRebinder
{
    /** @return array{profileId:?string,model:?string,effort:?string}|null */
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
            $effort = $m->effort; // 强度来自 meta（registry 不记 effort）；null = 未选择
            if ($m->profileId !== null && $m->profileId !== '') {
                try {
                    $registry->bind($loop, $m->profileId, $m->model);
                    $resolved = $registry->resolvedSelection();
                    return [
                        'profileId' => $resolved['profileId'] ?? $m->profileId,
                        'model' => $resolved['model'] ?? $m->model,
                        'effort' => $effort,
                    ];
                } catch (\InvalidArgumentException) {
                    // 会话存的 profile 已被删：回落默认档，profile/model 是死键一并剥离（留着只会再失败）
                }
            }
            // effort-only / profile 已删 / 完全无选择 → 默认档；有 effort 才记录（全空保持 null 不写脏）
            $registry->bind($loop, null, null);
            return $effort !== null ? ['profileId' => null, 'model' => null, 'effort' => $effort] : null;
        }
        $registry->bind($loop, null, null);
        return null;
    }
}
