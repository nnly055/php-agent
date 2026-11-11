<?php

declare(strict_types=1);

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;

/**
 * 项目层插件：注册同名 provider 'vendor'，验证项目层覆盖 composer 层。
 */
final class OverridingPlugin implements Plugin
{
    public function name(): string
    {
        return 'overrider';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $kernel->registerProvider('vendor', static fn () => new PhpAgent\LLM\FakeLLMClient([]));
    }
}
