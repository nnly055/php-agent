<?php

declare(strict_types=1);

namespace PhpAgent\Session;

final class SessionMeta
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly int $messageCount,
        public readonly ?string $profileId = null,
        public readonly ?string $model = null,
    ) {
    }

    /** 从首条用户输入派生标题（截断到 40 字符） */
    public static function deriveTitle(array $messages): string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') !== 'user') {
                continue;
            }
            $hasImage = false;
            foreach ($m['content'] as $b) {
                if (($b['type'] ?? '') === 'image') {
                    $hasImage = true;
                }
                if (($b['type'] ?? '') === 'text' && trim((string) ($b['text'] ?? '')) !== '') {
                    $t = trim((string) preg_replace('/\s+/u', ' ', $b['text']));
                    return mb_strlen($t) > 40 ? mb_substr($t, 0, 40) . '…' : $t;
                }
            }
            if ($hasImage) {
                return '[图片]';
            }
        }
        return '(空会话)';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'messageCount' => $this->messageCount,
            'profileId' => $this->profileId,
            'model' => $this->model,
        ];
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['id'] ?? ''),
            (string) ($a['title'] ?? '(空会话)'),
            (int) ($a['createdAt'] ?? 0),
            (int) ($a['updatedAt'] ?? 0),
            (int) ($a['messageCount'] ?? 0),
            // 旧数据（本功能上线前写入）缺这两个键 → 默认 null
            isset($a['profileId']) ? (string) $a['profileId'] : null,
            isset($a['model']) ? (string) $a['model'] : null,
        );
    }
}
