<?php

declare(strict_types=1);

namespace PhpAgent\Command;

use PhpAgent\Kernel\AgentKernel;

/**
 * 斜杠命令契约。execute 返回展示文本；需要切换会话时用 CommandContext 的引用。
 */
interface Command
{
    public function description(): string;

    /** @param string[] $args 去掉命令名后的参数 */
    public function execute(array $args, CommandContext $ctx): string;
}
