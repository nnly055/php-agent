<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\LLM\AnthropicClient;

/**
 * Anthropic Messages API 原生协议（provider id: 'anthropic'）。
 */
final class AnthropicPlugin implements Plugin
{
    public function name(): string
    {
        return 'provider-anthropic';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $kernel->registerProvider('anthropic', static fn (AgentKernel $k) => new AnthropicClient($k->config()));
    }
}
