<?php

declare(strict_types=1);

namespace PhpAgent\Agent;

use PhpAgent\Skills\SkillRegistry;

/**
 * 技能加载工具：LLM 按技能名取用 SKILL.md 正文。
 * 工具 description 动态携带技能清单（模型由此知道有哪些技能可用）；
 * 实现 ReadOnlyTool——正文是纯内容无副作用，ask 权限模式自动放行。
 */
final class SkillTool implements Tool, ReadOnlyTool
{
    /** 单条技能 description 进清单的最大字符数 */
    private const ENTRY_MAX_CHARS = 200;

    /** 技能清单总长上限（超过截断并注明） */
    private const LIST_MAX_CHARS = 4000;

    public function __construct(private readonly SkillRegistry $skills)
    {
    }

    public function name(): string
    {
        return 'skill';
    }

    public function description(): string
    {
        $lines = ['加载工作流指引（skill）。当任务与某个技能的描述匹配时优先调用本工具，返回该技能的完整指引。可用技能:'];
        $capped = false;
        foreach ($this->skills->all() as $skill) {
            $desc = trim($skill->description) !== '' ? $skill->description : '(无描述)';
            if (mb_strlen($desc) > self::ENTRY_MAX_CHARS) {
                $desc = mb_substr($desc, 0, self::ENTRY_MAX_CHARS) . '…';
            }
            $entry = "- {$skill->name}: {$desc}";
            // 先判断后追加：放下一条会超上限就停（清单总长保护协议体）
            if (mb_strlen(implode("\n", [...$lines, $entry])) > self::LIST_MAX_CHARS) {
                $capped = true;
                break;
            }
            $lines[] = $entry;
        }
        if ($capped) {
            $lines[] = '（技能过多，仅列出部分）';
        }
        return implode("\n", $lines);
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => '要加载的技能名'],
            ],
            'required' => ['name'],
        ];
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function execute(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        $skill = $this->skills->find($name);
        if ($skill === null) {
            return "ERROR: 未知技能 '{$name}'（可用: " . implode(', ', array_keys($this->skills->all())) . '）';
        }
        return $skill->body; // 正文原文返回（空正文 = 空串，合法 tool_result）
    }
}
