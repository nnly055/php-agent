<?php

declare(strict_types=1);

namespace PhpAgent\Session;

/**
 * 会话持久化契约——插件化的核心扩展点。
 *
 * 契约：
 * - 存储内容 = 归一化消息数组（AgentLoop 维护的格式），内部 JSON 带 schemaVersion 留迁移空间
 * - save 为整段原子覆盖（实现内部可优化为增量，接口不变）
 * - title 取首条用户输入，首次保存后固定
 */
interface SessionStore
{
    /** @return SessionMeta[] 按 updatedAt 倒序 */
    public function list(int $limit = 50): array;

    /** 读取归一化消息历史；不存在返回 [] */
    public function load(string $sessionId): array;

    public function save(string $sessionId, array $messages): void;

    public function delete(string $sessionId): void;
}
