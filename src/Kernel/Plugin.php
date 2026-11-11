<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

/**
 * 插件契约：register() 里通过 AgentKernel 注册扩展点（工具 / provider / 会话存储 / 钩子 / 命令）。
 *
 * 加载顺序 = 内置 → composer 包（Phase 3）→ 项目 plugins/ 目录，后注册者覆盖同 id。
 */
interface Plugin
{
    /** 唯一标识，如 'core-tools' */
    public function name(): string;

    public function version(): string;

    public function register(AgentKernel $kernel): void;
}
