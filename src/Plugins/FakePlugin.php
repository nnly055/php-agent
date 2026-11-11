<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\LLM\FakeLLMClient;

/**
 * 离线演示 provider（provider id: 'fake'），免 API key 体验完整 agent 流程。
 */
final class FakePlugin implements Plugin
{
    public function name(): string
    {
        return 'provider-fake';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $kernel->registerProvider('fake', static fn () => FakeLLMClient::demo());
    }
}
