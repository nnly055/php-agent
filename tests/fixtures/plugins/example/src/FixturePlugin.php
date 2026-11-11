<?php

declare(strict_types=1);

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Tools\WorkspaceTool;

/**
 * 测试夹具插件：注册一个 fixture_tool 验证项目插件发现机制。
 */
final class FixturePlugin implements Plugin
{
    public function name(): string
    {
        return 'fixture';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $kernel->registerTool(new class ('/tmp') extends WorkspaceTool {
            public function name(): string
            {
                return 'fixture_tool';
            }

            public function description(): string
            {
                return '测试夹具工具';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return 'fixture-ok';
            }
        });
    }
}
