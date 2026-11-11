<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\LLM\OpenAICompatClient;

/**
 * OpenAI 兼容协议，覆盖 GLM / DeepSeek / Kimi / Qwen / OpenAI（provider id: 'openai'）。
 */
final class OpenAICompatPlugin implements Plugin
{
    public function name(): string
    {
        return 'provider-openai';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $kernel->registerProvider('openai', static fn (AgentKernel $k) => new OpenAICompatClient($k->config()));
    }
}
