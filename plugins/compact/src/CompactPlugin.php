<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;

/**
 * compact 插件：上下文压缩——before_llm_request 自动阈值压缩 + /compact 手动命令。
 * 同时是「before_llm_request 钩子（引用裁剪）+ registerCommand」双扩展点的参考实现。
 *
 * provider 解析：AGENT_COMPACT_PROVIDER 优先（--fake 离线演示设 fake），
 * 空 = AGENT_PROVIDER。惰性构造：注册时不实例化客户端，首次压缩才解析。
 * 构造参数全部可空：生产装配（plugin.php 无参实例化）走 Config；
 * 测试经构造参数显式注入工厂与阈值。
 */
final class CompactPlugin implements Plugin
{
    public function __construct(
        private readonly ?\Closure $compactorFactory = null,
        private readonly ?int $contextTokens = null,
        private readonly ?float $threshold = null,
    ) {
    }

    public function name(): string
    {
        return 'compact';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $config = $kernel->config();
        $contextTokens = $this->contextTokens ?? $config->contextTokens();
        $threshold = $this->threshold ?? $config->compactThreshold();

        if ($this->compactorFactory !== null) {
            $factory = $this->compactorFactory;
        } else {
            $providerId = $config->compactProvider();
            if ($providerId !== '' && $kernel->hasProvider($providerId)) {
                $factory = static fn (): Compactor => new Compactor(
                    $kernel->provider($providerId),
                    $contextTokens,
                    $threshold,
                );
            } else {
                // 未显式指定压缩 provider：跟随当前对话生效的 profile client（per-turn 切换后压缩同源）。
                // registry 查找同样推迟到压缩时刻：bin/server 在插件注册之后才挂载 registry，register 时必为 null。
                // contextTokens 同时刻求值：跟随当前对话模型的 per-model contextTokens（未解析/未设置回落全局值）。
                $fallbackId = $config->provider();
                $factory = static fn (): Compactor => new Compactor(
                    $kernel->profileRegistry()?->activeClient() ?? $kernel->provider($fallbackId),
                    $kernel->profileRegistry()?->activeContextTokens() ?? $contextTokens,
                    $threshold,
                );
            }
        }

        $kernel->addHook(new CompactHook($factory, $contextTokens, $threshold));
        $kernel->registerCommand('compact', new CompactCommand($factory));
    }
}
