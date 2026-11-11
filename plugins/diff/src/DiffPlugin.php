<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Diff;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;

/**
 * diff 插件：write_file 变更可视化。
 * - 覆盖内置 write_file，返回文本附带 diff（模型与 UI 都能看到改动）
 * - before_tool_call 钩子在授权确认前打印变更预览（先看 diff、再决定 Y/n/a）
 *
 * 同时是「工具装饰器 + 生命周期钩子」双扩展点的官方参考实现。
 */
final class DiffPlugin implements Plugin
{
    public function name(): string
    {
        return 'diff';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $workspace = $kernel->config()->workspace();
        $kernel->registerTool(DiffWriteFileTool::wrap($workspace)); // 同名覆盖内置 write_file
        $output = getenv('AGENT_UI') === 'server' ? static function (string $s): void {} : null;
        $kernel->addHook(new DiffPreviewHook($workspace, $output)); // 先于 ConfirmHook 执行；server 静默
    }
}
