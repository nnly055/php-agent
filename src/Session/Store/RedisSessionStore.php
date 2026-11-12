<?php

declare(strict_types=1);

namespace PhpAgent\Session\Store;

use PhpAgent\Session\SessionMeta;
use PhpAgent\Session\SessionStore;

/**
 * Redis 存储：server 多进程 / 桌面壳常驻场景。
 * 数据结构：<prefix>session:<id> = JSON（schemaVersion + meta + messages），
 *           <prefix>index = ZSET（score=updatedAt，member=id）。
 */
final class RedisSessionStore implements SessionStore
{
    private const INDEX_KEY = 'php-agent:index';

    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly int $dbIndex = 0,
        private readonly ?string $password = null,
        private readonly string $prefix = 'php-agent:',
    ) {
    }

    private function conn(): \Redis
    {
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('未安装 ext-redis，无法使用 redis 会话存储');
        }
        $r = new \Redis();
        if (@$r->connect($this->host, $this->port, 3.0) === false) {
            throw new \RuntimeException("Redis 连接失败: {$this->host}:{$this->port}");
        }
        if ($this->password !== null && !$r->auth($this->password)) {
            throw new \RuntimeException('Redis 认证失败');
        }
        if ($this->dbIndex !== 0 && !$r->select($this->dbIndex)) {
            throw new \RuntimeException("Redis 选择 db 失败: {$this->dbIndex}");
        }
        return $this->redis = $r;
    }

    private function key(string $id): string
    {
        return $this->prefix . 'session:' . $id;
    }

    public function list(int $limit = 50): array
    {
        $r = $this->conn();
        $ids = $r->zRevRange(self::INDEX_KEY, 0, $limit - 1) ?: [];
        $metas = [];
        foreach ($ids as $id) {
            $j = json_decode((string) $r->get($this->key((string) $id)), true);
            if (is_array($j) && isset($j['meta'])) {
                $metas[] = SessionMeta::fromArray($j['meta']);
            }
        }
        return $metas;
    }

    public function load(string $sessionId): array
    {
        $j = json_decode((string) $this->conn()->get($this->key($sessionId)), true);
        if (!is_array($j) || ($j['schemaVersion'] ?? 0) !== 1 || !isset($j['messages'])) {
            return [];
        }
        return $j['messages'];
    }

    public function save(string $sessionId, array $messages, ?array $selection = null): void
    {
        $r = $this->conn();
        $old = json_decode((string) $r->get($this->key($sessionId)), true);
        $sel = $selection ?? []; // null = 保留旧值
        $meta = new SessionMeta(
            $sessionId,
            $old['meta']['title'] ?? SessionMeta::deriveTitle($messages),
            (int) ($old['meta']['createdAt'] ?? time()),
            time(),
            count($messages),
            $sel['profileId'] ?? ($old['meta']['profileId'] ?? null),
            $sel['model'] ?? ($old['meta']['model'] ?? null),
        );
        $r->set($this->key($sessionId), json_encode(
            ['schemaVersion' => 1, 'meta' => $meta->toArray(), 'messages' => $messages],
            JSON_UNESCAPED_UNICODE
        ));
        $r->zAdd(self::INDEX_KEY, time(), $sessionId);
    }

    public function delete(string $sessionId): void
    {
        $r = $this->conn();
        $r->del($this->key($sessionId));
        $r->zRem(self::INDEX_KEY, $sessionId);
    }
}
