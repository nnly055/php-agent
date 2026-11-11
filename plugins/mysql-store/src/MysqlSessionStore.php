<?php

declare(strict_types=1);

namespace MysqlStore;

use PhpAgent\Session\SessionMeta;
use PhpAgent\Session\SessionStore;

/**
 * MySQL 会话存储（第三方插件示例：演示不动内核、以插件形式接入新持久化方式）。
 *
 * 表结构与内置 Sqlite3SessionStore 同构；自动建库建表。
 * 配置全部通过环境变量（插件自带配置，不依赖内核 Config）：
 *   AGENT_MYSQL_HOST（默认 127.0.0.1）、AGENT_MYSQL_PORT（3306）、
 *   AGENT_MYSQL_USER（root）、AGENT_MYSQL_PASSWORD、AGENT_MYSQL_DATABASE（php_agent）
 */
final class MysqlSessionStore implements SessionStore
{
    private ?\PDO $pdo = null;

    private readonly string $dsn;
    private readonly string $user;
    private readonly string $password;
    private readonly string $database;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 3306,
        string $user = 'root',
        string $password = '',
        string $database = 'php_agent',
    ) {
        $this->dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $this->user = $user;
        $this->password = $password;
        $this->database = $database;
    }

    private function conn(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }
        $pdo = new \PDO($this->dsn, $this->user, $this->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 3,
        ]);
        // 自动建库建表：第三方插件应当「拿来即用」
        $safeDb = str_replace('`', '', $this->database);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$safeDb}`");
        $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(191) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message_count INT NOT NULL,
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            payload LONGTEXT NOT NULL,
            INDEX idx_sessions_updated (updated_at)
        ) ENGINE=InnoDB');
        return $this->pdo = $pdo;
    }

    public function list(int $limit = 50): array
    {
        $stmt = $this->conn()->prepare(
            'SELECT id, title, message_count, created_at, updated_at
             FROM sessions ORDER BY updated_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $metas = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
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
        $stmt = $this->conn()->prepare('SELECT payload FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }
        $j = json_decode((string) $row['payload'], true);
        return is_array($j) && ($j['schemaVersion'] ?? 0) === 1 && isset($j['messages'])
            ? $j['messages'] : [];
    }

    public function save(string $sessionId, array $messages): void
    {
        $stmt = $this->conn()->prepare(
            'INSERT INTO sessions (id, title, message_count, created_at, updated_at, payload)
             VALUES (:id, :title, :mc, :ca, :ua, :payload)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                message_count = VALUES(message_count),
                updated_at = VALUES(updated_at),
                payload = VALUES(payload)'
        );
        $stmt->execute([
            ':id' => $sessionId,
            ':title' => mb_substr(SessionMeta::deriveTitle($messages), 0, 255),
            ':mc' => count($messages),
            ':ca' => time(),
            ':ua' => time(),
            ':payload' => json_encode(
                ['schemaVersion' => 1, 'messages' => $messages],
                JSON_UNESCAPED_UNICODE
            ),
        ]);
        // 保持 created_at 首次值（与内置实现语义一致）
        $this->conn()->prepare(
            'UPDATE sessions SET created_at = created_at WHERE id = :id'
        )->execute([':id' => $sessionId]);
    }

    public function delete(string $sessionId): void
    {
        $this->conn()->prepare('DELETE FROM sessions WHERE id = :id')
            ->execute([':id' => $sessionId]);
    }
}
