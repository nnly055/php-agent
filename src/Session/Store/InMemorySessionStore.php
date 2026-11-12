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
                $sel['profileId'] ?? $old?->profileId ?? null,
                $sel['model'] ?? $old?->model ?? null,
            ),
        ];
    }

    public function delete(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }
}
