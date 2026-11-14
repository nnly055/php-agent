<?php

declare(strict_types=1);

namespace PhpAgent\Command;

use PhpAgent\Kernel\AgentKernel;

/**
 * 命令执行上下文：messages / sessionId / selection 以引用持有，
 * 命令可直接切换当前会话或改写当前会话的模型选择。
 */
final class CommandContext
{
    public function __construct(
        private readonly AgentKernel $kernel,
        public array &$messages,
        public string &$sessionId,
        public ?array &$selection, // null = 本会话未做 /model 选择（沿用全局默认档）
    ) {
    }

    public function kernel(): AgentKernel
    {
        return $this->kernel;
    }

    /** 恢复某个会话（或用新 id 开启新会话） */
    public function switchSession(string $id, array $messages): void
    {
        $this->sessionId = $id;
        $this->messages = $messages;
    }
}
