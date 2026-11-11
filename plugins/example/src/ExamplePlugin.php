<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Example;

use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Tools\WorkspaceTool;

/**
 * 示例插件：演示「注册一个工具」和「注册一个命令」两个扩展点。
 * 启用后在 CLI 里输入 /tools 和 /help 即可看到新增的 now 工具与 /hello 命令。
 */
final class ExamplePlugin implements Plugin
{
    public function name(): string
    {
        return 'example';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        // 扩展点 1：新工具 now —— 让模型能获取当前时间
        $kernel->registerTool(new class ($kernel->config()->workspace()) extends WorkspaceTool {
            public function name(): string
            {
                return 'now';
            }

            public function description(): string
            {
                return '获取当前的日期时间（演示插件如何新增工具）';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return date('Y-m-d H:i:s');
            }
        });

        // 扩展点 2：新命令 /hello
        $kernel->registerCommand('hello', new class implements Command {
            public function description(): string
            {
                return '打个招呼（演示插件如何新增命令）';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                return 'Hello from 插件！当前会话: ' . $ctx->sessionId;
            }
        });
    }
}
