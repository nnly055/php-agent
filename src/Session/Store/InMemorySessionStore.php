<?php

declare(strict_types=1);

namespace PhpAgent\Session\Store;

use PhpAgent\Session\SessionMeta;
use PhpAgent\Session\SessionStore;

/**
 * 内存存储：默认兜底实现，即插件化之前的行为（不持久化）。
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, array{messages: array, meta: SessionMeta}> */
    private array $sessions = [];

    public function list(int $limit = 50): array
    {
        $metas = array_map(static fn (array $s) => $s['meta'], $this->sessions);
        usort($metas, static fn (SessionMeta $a, SessionMeta $b) => $b->updatedAt <=> $a->updatedAt);
        return array_slice($metas, 0, $limit);
    }

    public function load(string $sessionId): array
    {
        return $this->sessions[$sessionId]['messages'] ?? [];
    }

    public function save(string $sessionId, array $messages, ?array $selection = null): void
    {
        $old = $this->sessions[$sessionId]['meta'] ?? null;
        $sel = $selection ?? []; // null = 保留旧值
        $this->sessions[$sessionId] = [
            'messages' => $messages,
            'meta' => new SessionMeta(
                $sessionId,
                $old?->title ?? SessionMeta::deriveTitle($messages),
                $old?->createdAt ?? time(),
                time(),
                count($messages),
                // selection 键存在即权威（含显式 null）：?? 会把 /effort-only 轮的显式 null 当缺失，抹掉已存选择
                array_key_exists('profileId', $sel) ? $sel['profileId'] : ($old?->profileId ?? null),
                array_key_exists('model', $sel) ? $sel['model'] : ($old?->model ?? null),
                array_key_exists('effort', $sel) ? $sel['effort'] : ($old?->effort ?? null),
            ),
        ];
    }

    public function delete(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }
}
