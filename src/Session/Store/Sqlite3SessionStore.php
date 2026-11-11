<?php

declare(strict_types=1);

namespace PhpAgent\Session\Store;

use PhpAgent\Session\SessionMeta;
use PhpAgent\Session\SessionStore;

/**
 * SQLite 存储：单文件 db（<dir>/sessions.db），会话多时查询/清理比 JSON 快。
 * 依赖 ext-sqlite3（本机已装；打包分发时需保留该扩展）。
 */
final class Sqlite3SessionStore implements SessionStore
{
    private \SQLite3 $db;

    public function __construct(string $dir)
    {
        if (!class_exists(\SQLite3::class)) {
            throw new \RuntimeException('未安装 ext-sqlite3，无法使用 sqlite3 会话存储');
        }
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException("无法创建会话存储目录: {$dir}");
        }
        $this->db = new \SQLite3($dir . '/sessions.db');
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(3000);
        $this->db->exec('CREATE TABLE IF NOT EXISTS sessions (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            message_count INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            payload TEXT NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_updated ON sessions(updated_at DESC)');
    }

    public function list(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, title, message_count, created_at, updated_at
             FROM sessions ORDER BY updated_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $metas = [];
        while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            $metas[] = new SessionMeta(
                (string) $row['id'],
                (string) $row['title'],
                (int) $row['created_at'],
                (int) $row['updated_at'],
                (int) $row['message_count'],
            );
        }
        return $metas;
    }

    public function load(string $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT payload FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $sessionId, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return [];
        }
        $j = json_decode((string) $row['payload'], true);
        if (!is_array($j) || ($j['schemaVersion'] ?? 0) !== 1 || !isset($j['messages'])) {
            return [];
        }
        return $j['messages'];
    }

    public function save(string $sessionId, array $messages): void
    {
        $stmt = $this->db->prepare('SELECT created_at FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $sessionId, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $createdAt = $row !== false ? (int) $row['created_at'] : time();

        $stmt = $this->db->prepare(
            'INSERT INTO sessions (id, title, message_count, created_at, updated_at, payload)
             VALUES (:id, :title, :mc, :ca, :ua, :payload)
             ON CONFLICT(id) DO UPDATE SET
                title = excluded.title,
                message_count = excluded.message_count,
                updated_at = excluded.updated_at,
                payload = excluded.payload'
        );
        $stmt->bindValue(':id', $sessionId, SQLITE3_TEXT);
        $stmt->bindValue(':title', SessionMeta::deriveTitle($messages), SQLITE3_TEXT);
        $stmt->bindValue(':mc', count($messages), SQLITE3_INTEGER);
        $stmt->bindValue(':ca', $createdAt, SQLITE3_INTEGER);
        $stmt->bindValue(':ua', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':payload', json_encode(
            ['schemaVersion' => 1, 'messages' => $messages],
            JSON_UNESCAPED_UNICODE
        ), SQLITE3_TEXT);
        $stmt->execute();
    }

    public function delete(string $sessionId): void
    {
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->bindValue(':id', $sessionId, SQLITE3_TEXT);
        $stmt->execute();
    }

    public function __destruct()
    {
        try {
            $this->db->close();
        } catch (\Throwable) {
        }
    }
}
