<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Diff;

use PhpAgent\Hook\AgentHook;

/**
 * write_file 变更预览钩子：在授权确认（ConfirmHook）之前把 diff 打到终端，
 * 用户「先看变更、再决定 Y/n/a」。新文件给出摘要提示。
 *
 * 加载顺序保证：项目插件注册的钩子先于 bin/agent 手动装配的 ConfirmHook 执行。
 * 注意：server 模式下输出进入服务进程日志（前端可在 tool_result 里看到 diff）。
 */
final class DiffPreviewHook implements AgentHook
{
    public function __construct(private readonly string $workspace)
    {
    }

    public function events(): array
    {
        return ['before_tool_call'];
    }

    public function handle(string $event, array $payload): void
    {
        if (($payload['tool'] ?? '') !== 'write_file') {
            return;
        }
        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        $path = trim((string) ($input['path'] ?? ''));
        if ($path === '') {
            return;
        }

        $abs = str_starts_with($path, '/') ? $path : $this->workspace . '/' . $path;

        echo "\n  \033[36m▶ 变更预览: {$path}\033[0m\n";

        if (!is_file($abs)) {
            $bytes = strlen((string) ($input['content'] ?? ''));
            $lines = substr_count((string) ($input['content'] ?? ''), "\n") + 1;
            echo "  \033[32m(新文件 · {$lines} 行 · {$bytes} bytes)\033[0m\n";
            return;
        }
        if ((filesize($abs) ?: 0) > DiffRenderer::MAX_DIFF_BYTES) {
            echo "  \033[2m(原文件过大，跳过预览)\033[0m\n";
            return;
        }

        $old = (string) file_get_contents($abs);
        $diff = DiffRenderer::render($old, (string) ($input['content'] ?? ''), 40);
        if ($diff === '') {
            echo "  \033[2m(内容无变化)\033[0m\n";
            return;
        }
        echo $this->indent($diff) . "\n";
    }

    private function indent(string $text): string
    {
        return implode("\n", array_map(
            static fn (string $l): string => '  ' . $l,
            preg_split('/\r?\n/', $text) ?: []
        ));
    }
}
