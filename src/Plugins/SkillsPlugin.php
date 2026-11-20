<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Agent\SkillTool;
use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;
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
        // /skills：列出技能清单（CLI 专属；server 无命令分发，桌面走模型自主触发）
        $kernel->registerCommand('skills', new class($registry, $workspace) implements Command {
            public function __construct(
                private readonly SkillRegistry $registry,
                private readonly string $workspace,
            ) {
            }

            public function description(): string
            {
                return '列出可用技能';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $all = $this->registry->all();
                if ($all === []) {
                    // 防御分支：命令仅在非空 registry 时注册（SkillsPlugin::register 保证）
                    return "(无已安装技能：创建 {$this->workspace}/.agent/skills/<name>/SKILL.md)";
                }
                $lines = ['可用技能:'];
                foreach ($all as $skill) {
                    $desc = trim($skill->description) !== '' ? $skill->description : '(无描述)';
                    $lines[] = sprintf('  %-14s %s（%s）', $skill->name, $desc, $skill->dir);
                }
                $lines[] = '手动注入: /skill <name>';
                return implode("\n", $lines);
            }
        });

        // /skill <name>：把技能正文作为 user 消息注入会话历史（引用写穿，随 save 落盘、随回放透传）
        $kernel->registerCommand('skill', new class($registry) implements Command {
            public function __construct(private readonly SkillRegistry $registry)
            {
            }

            public function description(): string
            {
                return '加载技能指引进会话（/skill <name>）';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $all = $this->registry->all();
                $available = implode(', ', array_keys($all));
                $name = trim((string) ($args[0] ?? ''));
                if ($name === '') {
                    return "用法: /skill <name>（可用: {$available}）";
                }
                $skill = $this->registry->find($name);
                if ($skill === null) {
                    return "✗ 未知技能 '{$name}'（可用: {$available}）";
                }
                $text = "已加载技能 {$name}。以下是该技能的完整指引，请在后续工作中遵循：\n\n"
                    . "<skill name=\"{$name}\">\n{$skill->body}\n</skill>";
                $ctx->messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
                return "✓ 已加载技能 {$name}（正文已注入会话，下一条消息起生效）";
            }
        });
    }
}
