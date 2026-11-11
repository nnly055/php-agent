<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

/**
 * 一次压缩的结果：compact() 的返回值，钩子与命令共用。
 * note 同时承载成功提示与失败原因；未尝试压缩（无可压缩边界）时 note 为空串。
 */
final class CompactionResult
{
    public function __construct(
        public readonly bool $compacted,
        public readonly string $note,
        public readonly int $removedMessages,
        public readonly int $beforeTokens,
        public readonly int $afterTokens,
    ) {
    }
}
