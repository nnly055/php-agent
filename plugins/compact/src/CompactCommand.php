<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;

/**
 * /compact：手动压缩当前会话历史（忽略自动阈值，边界规则与自动压缩一致）。
 * 经 CommandContext 的 messages 引用就地替换，下一轮对话即用压缩后历史。
 */
final class CompactCommand implements Command
{
    /** @param \Closure(): Compactor $compactorFactory */
    public function __construct(private readonly \Closure $compactorFactory)
    {
    }

    public function description(): string
    {
        return '手动压缩当前会话历史（LLM 摘要，保留当前任务）';
    }

    public function execute(array $args, CommandContext $ctx): string
    {
        if (Compactor::findSplit($ctx->messages) === 0) {
            return '（当前历史较短，无需压缩）';
        }
        $result = ($this->compactorFactory)()->compact($ctx->messages, '');
        return $result->note !== '' ? $result->note : '（无可压缩内容）';
    }
}
