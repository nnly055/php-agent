<?php

declare(strict_types=1);

namespace PhpAgent\Command;

use PhpAgent\Kernel\AgentKernel;

/**
 * 命令执行上下文：messages / sessionId 以引用持有，命令可直接切换当前会话。
 */
final class CommandContext
{
    public function __construct(
        private readonly AgentKernel $kernel,
        public array &$messages,
        public string &$sessionId,
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
