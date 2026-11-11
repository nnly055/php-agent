<?php

declare(strict_types=1);

namespace PhpAgent\Session\Store;

use PhpAgent\Session\SessionMeta;
use PhpAgent\Session\SessionStore;

/**
 * JSON 文件存储：零依赖的默认持久化实现，每个会话一个文件，人可读、易 diff。
 * 文件位置见 SessionPaths；写入用「临时文件 + rename」保证原子性。
 */
final class JsonFileSessionStore implements SessionStore
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException("无法创建会话存储目录: {$dir}");
        }
    }

    private function file(string $sessionId): string
    {
        // id 来自用户输入（server 端任意字符串），文件名做白名单净化
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $sessionId) ?: 'session';
        return $this->dir . '/' . $safe . '.json';
    }

    public function list(int $limit = 50): array
    {
        $metas = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $j = json_decode((string) file_get_contents($file), true);
            if (is_array($j) && isset($j['meta'])) {
                $metas[] = SessionMeta::fromArray($j['meta']);
            }
        }
        usort($metas, static fn (SessionMeta $a, SessionMeta $b) => $b->updatedAt <=> $a->updatedAt);
        return array_slice($metas, 0, $limit);
    }

    public function load(string $sessionId): array
    {
        $j = json_decode((string) @file_get_contents($this->file($sessionId)), true);
        if (!is_array($j) || ($j['schemaVersion'] ?? 0) !== 1 || !isset($j['messages'])) {
            return [];
        }
        return $j['messages'];
    }

    public function save(string $sessionId, array $messages): void
    {
        $file = $this->file($sessionId);
        $old = json_decode((string) @file_get_contents($file), true);
        $meta = new SessionMeta(
            $sessionId,
            $old['meta']['title'] ?? SessionMeta::deriveTitle($messages),
            (int) ($old['meta']['createdAt'] ?? time()),
            time(),
            count($messages),
        );
        $payload = ['schemaVersion' => 1, 'meta' => $meta->toArray(), 'messages' => $messages];
        $tmp = $file . '.tmp.' . uniqid();
        if (file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            throw new \RuntimeException("会话写入失败: {$file}");
        }
        rename($tmp, $file); // 同目录 rename 为原子操作
    }

    public function delete(string $sessionId): void
    {
        @unlink($this->file($sessionId));
    }
}
