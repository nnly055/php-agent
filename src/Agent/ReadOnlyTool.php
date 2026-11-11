<?php

declare(strict_types=1);

namespace PhpAgent\Agent;

/**
 * 标记接口：实现者向权限层声明自己是只读工具（ConfirmHook 借助 ToolRegistry 自动放行）。
 * 内置五件套走 ConfirmHook 硬编码名单，无需实现本接口。
 */
interface ReadOnlyTool
{
    public function readOnly(): bool;
}
