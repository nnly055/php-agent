<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Tools\GrepTool;
use PhpAgent\Tools\ListDirTool;
use PhpAgent\Tools\ReadFileTool;
use PhpAgent\Tools\RunCommandTool;
use PhpAgent\Tools\WriteFileTool;

/**
 * 内置五件套工具：read_file / write_file / list_dir / run_command / grep。
 */
final class CoreToolsPlugin implements Plugin
{
    public function name(): string
    {
        return 'core-tools';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $ws = $kernel->config()->workspace();
        $kernel->registerTool(new ReadFileTool($ws));
        $kernel->registerTool(new WriteFileTool($ws));
        $kernel->registerTool(new ListDirTool($ws));
        $kernel->registerTool(new RunCommandTool($ws));
        $kernel->registerTool(new GrepTool($ws));
    }
}
