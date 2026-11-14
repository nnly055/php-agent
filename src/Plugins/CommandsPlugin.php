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

        // 会话内切换模型：目录来自 ProfileRegistry（CLI 装配自 AGENT_PROFILES）。
        // 切换 per-turn 生效——setClient 立即替换，下一条消息即用新模型；选择写穿 ctx->selection
        // 由主循环随 save 落盘。/model 与 /compact 一样为 CLI 专属（server 无命令分发）。
        $kernel->registerCommand('model', new class implements Command {
            public function description(): string
            {
                return '查看/切换模型（/model [profile [model]]）';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                $registry = $ctx->kernel()->profileRegistry();
                if ($registry === null) {
                    return '✗ 模型目录不可用（profileRegistry 未装配）';
                }
                if ($args === []) {
                    return $this->renderCatalog($registry, $ctx);
                }

                $profileId = trim((string) $args[0]);
                if ($profileId === '') {
                    return '✗ profile id 不能为空';
                }
                $model = isset($args[1]) && trim((string) $args[1]) !== '' ? trim((string) $args[1]) : null;

                $prevProvider = $this->providerOf($registry, $ctx->selection);
                try {
                    $client = $registry->resolve($profileId, $model);
                    $sel = $registry->resolvedSelection()
                        ?? ['profileId' => $profileId, 'model' => (string) $model]; // 防御：目录态必有 resolvedSelection
                } catch (\InvalidArgumentException $e) {
                    return '✗ ' . $e->getMessage();
                }
                $ctx->kernel()->loop()->setClient($client);
                $ctx->selection = $sel;

                $newProvider = $this->providerOf($registry, $sel);
                $protocol = $newProvider === 'anthropic' ? 'anthropic 原生' : ($newProvider === 'fake' ? 'fake（离线）' : 'openai 兼容');
                $hint = ($prevProvider !== null && $prevProvider !== $newProvider && $newProvider !== 'fake')
                    ? "，历史将回放到新协议（{$newProvider}）"
                    : '';
                return "✓ 已切换到 {$sel['profileId']} / {$sel['model']}（{$protocol}）{$hint}";
            }

            /** @param array{id:string,name?:string,provider:string,models:array<int,array{name:string}>|array<int,string>} ... 目录条目 */
            private function renderCatalog(\PhpAgent\Kernel\ProfileRegistry $registry, CommandContext $ctx): string
            {
                $cat = $registry->profiles();
                $profiles = is_array($cat['profiles'] ?? null) ? $cat['profiles'] : [];
                if ($profiles === []) {
                    return '（模型目录为空：.env 配置 AGENT_PROFILES 启用会话内切换）';
                }
                $default = (string) ($cat['defaultProfile'] ?? '');
                $sel = $ctx->selection;
                $lines = ['可用模型:'];
                foreach ($profiles as $p) {
                    $pid = (string) ($p['id'] ?? '');
                    $provider = (string) ($p['provider'] ?? '');
                    $protocol = $provider === 'anthropic' ? 'anthropic 原生' : ($provider === 'fake' ? 'fake（离线）' : 'openai 兼容');
                    $models = array_map(
                        static fn ($m) => is_array($m) ? (string) ($m['name'] ?? '') : (string) $m,
                        is_array($p['models'] ?? null) ? $p['models'] : []
                    );
                    // ✓ = 当前会话生效（显式选择匹配；未选择 → 默认档首个模型）
                    $active = $sel !== null
                        ? ($sel['profileId'] === $pid)
                        : ($pid === $default);
                    $mark = $active ? '✓' : ' ';
                    $lines[] = sprintf('  %s %-12s %-14s (%s)  %s', $mark, $pid, (string) ($p['name'] ?? ''), $protocol, implode(', ', $models));
                }
                $lines[] = '切换: /model <profile> [model]（下一条消息生效）';
                return implode("\n", $lines);
            }

            /** selection 或默认档指向的 provider；未知 → null */
            private function providerOf(\PhpAgent\Kernel\ProfileRegistry $registry, ?array $sel): ?string
            {
                $cat = $registry->profiles();
                $profiles = is_array($cat['profiles'] ?? null) ? $cat['profiles'] : [];
                $pid = $sel['profileId'] ?? (string) ($cat['defaultProfile'] ?? '');
                foreach ($profiles as $p) {
                    if ((string) ($p['id'] ?? '') === $pid) {
                        return (string) ($p['provider'] ?? '');
                    }
                }
                return null;
            }
        });
    }
}
