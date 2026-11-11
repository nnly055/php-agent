<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Hook\AuditLogHook;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Session\SessionPaths;

/**
 * 审计插件：默认开启，把工具调用/结果追加写入 audit.jsonl（AGENT_AUDIT=off 关闭）。
 */
final class AuditPlugin implements Plugin
{
    public function name(): string
    {
        return 'audit';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $config = $kernel->config();
        if (!$config->audit()) {
            return;
        }
        $dir = $config->auditDir() ?: SessionPaths::storageDir($config);
        $kernel->addHook(new AuditLogHook($dir . '/audit.jsonl'));
    }
}
