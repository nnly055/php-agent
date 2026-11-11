<?php

declare(strict_types=1);

namespace PhpAgent\TestVendor;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\Tools\WorkspaceTool;

/**
 * 模拟第三方 composer 包提供的插件（fixture，由 composer-test 的 installed.json 声明）。
 */
final class VendorPlugin implements Plugin
{
    public function name(): string
    {
        return 'testvendor';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        // 演示 composer 包也能注册 provider
        $kernel->registerProvider('vendor', static fn () => new FakeLLMClient([]));

        $kernel->registerTool(new class ('/tmp') extends WorkspaceTool {
            public function name(): string
            {
                return 'vendor_tool';
            }

            public function description(): string
            {
                return 'composer 包插件示例工具';
            }

            public function schema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return 'vendor-ok';
            }
        });
    }
}
