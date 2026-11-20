<?php

declare(strict_types=1);

namespace PhpAgent\Skills;

/**
 * 技能值对象：一个 <目录>/SKILL.md 解析后的全部内容。
 * body 为正文原文（未裁剪），dir 为来源目录（/skills 展示用）。
 */
final class Skill
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $body,
        public readonly string $dir,
    ) {
    }
}
