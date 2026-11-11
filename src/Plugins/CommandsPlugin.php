<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;

/**
 * 内置斜杠命令：help / tools / plugins / sessions / resume / new。
 * /exit 是 shell 级控制流，由 CLI 入口直接处理，不走命令插件。
 */
final class CommandsPlugin implements Plugin
{
    public function name(): string
    {
        return 'commands';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $kernel->registerCommand('help', new class implements Command {
            public function description(): string
            {
                return '显示帮助';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $lines = ['可用命令:'];
                foreach ($ctx->kernel()->commands() as $name => $cmd) {
                    $lines[] = sprintf('  /%-10s %s', $name, $cmd->description());
                }
                $lines[] = '  /exit       退出（Ctrl+D 同效）';
                return implode("\n", $lines);
            }
        });

        $kernel->registerCommand('tools', new class implements Command {
            public function description(): string
            {
                return '列出已注册工具';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                return '已注册工具: ' . implode(', ', $ctx->kernel()->toolRegistry()->names());
            }
        });

        $kernel->registerCommand('plugins', new class implements Command {
            public function description(): string
            {
                return '列出已加载插件与会话存储';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $k = $ctx->kernel();
                $mode = $k->config()->pluginsSwitch() === 'off' ? '仅内置' : '含项目插件';
                $lines = ["已加载插件（{$mode}）:"];
                foreach ($k->plugins() as $name => $ver) {
                    $lines[] = sprintf('  %-18s v%s', $name, $ver);
                }
                $lines[] = '';
                $lines[] = '会话存储: ' . $this->shortName($k->sessionStore());
                $lines[] = 'LLM provider: ' . implode(', ', $k->providerIds());
                return implode("\n", $lines);
            }

            private function shortName(object $o): string
            {
                $c = get_class($o);
                $p = strrpos($c, '\\');
                return $p === false ? $c : substr($c, $p + 1);
            }
        });

        $kernel->registerCommand('sessions', new class implements Command {
            public function description(): string
            {
                return '列出最近会话';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $metas = $ctx->kernel()->sessionStore()->list(20);
                if ($metas === []) {
                    return '(还没有已保存的会话)';
                }
                $lines = ['最近会话:'];
                foreach ($metas as $m) {
                    $mark = $m->id === $ctx->sessionId ? ' ←当前' : '';
                    $lines[] = sprintf(
                        '  %-18s %s (%s · %d 条)%s',
                        $m->id,
                        $m->title,
                        date('m-d H:i', $m->updatedAt),
                        $m->messageCount,
                        $mark
                    );
                }
                return implode("\n", $lines);
            }
        });

        $kernel->registerCommand('resume', new class implements Command {
            public function description(): string
            {
                return '恢复会话（/resume [id]，无参数=恢复最近一个）';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $store = $ctx->kernel()->sessionStore();
                $id = $args[0] ?? null;
                if ($id === null) {
                    $latest = $store->list(1)[0] ?? null;
                    if ($latest === null) {
                        return '(没有可恢复的会话)';
                    }
                    $id = $latest->id;
                }
                $messages = $store->load($id);
                if ($messages === []) {
                    return "会话 {$id} 不存在或为空";
                }
                $ctx->switchSession($id, $messages);
                return "✓ 已恢复会话 {$id}（" . count($messages) . ' 条消息），继续输入即可';
            }
        });

        $kernel->registerCommand('new', new class implements Command {
            public function description(): string
            {
                return '开启新会话';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $id = 's-' . date('Ymd-His') . '-' . substr(uniqid(), -4);
                $ctx->switchSession($id, []);
                return "✓ 新会话 {$id} 已开启（旧会话已自动保存，/sessions 查看）";
            }
        });
    }
}
