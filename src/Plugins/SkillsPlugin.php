<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Agent\SkillTool;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Skills\SkillLoader;
use PhpAgent\Skills\SkillRegistry;

/**
 * 技能插件：扫描 SKILL.md，注册 skill 工具与 /skills /skill 命令。
 *
 * 零行为变化边界（契约 6 精神）：AGENT_SKILLS=off 或扫描后无任何技能 → 什么都不注册
 * （不注册工具、不注册命令、不挂 registry），与该功能不存在时完全一致。
 */
final class SkillsPlugin implements Plugin
{
    public function name(): string
    {
        return 'skills';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $config = $kernel->config();
        if (!$config->skillsEnabled()) {
            return;
        }

        $workspace = $config->workspace();
        $loader = new SkillLoader();
        $registry = $loader->scan(
            [$workspace . '/.agent/skills', ...$config->skillDirs()],
            $workspace
        );
        if ($registry->isEmpty()) {
            return; // 无技能 = 现行为零变化
        }

        foreach ($loader->warnings() as $warning) {
            fwrite(STDERR, "⚠ {$warning}\n");
        }

        $kernel->setSkillRegistry($registry);
        $kernel->registerTool(new SkillTool($registry));
        $this->registerCommands($kernel, $registry, $workspace);
    }

    private function registerCommands(AgentKernel $kernel, SkillRegistry $registry, string $workspace): void
    {
        // 命令在 Task 6（/skills 列表与 /skill 手动注入）实现——装配结构先行，占位于此
    }
}
