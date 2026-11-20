<?php

declare(strict_types=1);

namespace PhpAgent\Skills;

/**
 * 技能注册表：name → Skill 映射。
 * 覆盖语义与插件一致：后 add 者覆盖同名技能。
 */
final class SkillRegistry
{
    /** @var array<string, Skill> */
    private array $skills = [];

    public function add(Skill $skill): void
    {
        $this->skills[$skill->name] = $skill;
    }

    public function find(string $name): ?Skill
    {
        return $this->skills[$name] ?? null;
    }

    /** @return array<string, Skill> 按 add 顺序 */
    public function all(): array
    {
        return $this->skills;
    }

    public function isEmpty(): bool
    {
        return $this->skills === [];
    }
}
