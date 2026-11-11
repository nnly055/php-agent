<?php

declare(strict_types=1);

namespace MysqlStore;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;

/**
 * MySQL 持久化插件：加载后覆盖内置会话存储（项目层晚于内置层，覆盖语义保证生效）。
 */
final class MysqlStorePlugin implements Plugin
{
    public function name(): string
    {
        return 'mysql-store';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $kernel->setSessionStore(new MysqlSessionStore(
            getenv('AGENT_MYSQL_HOST') ?: '127.0.0.1',
            (int) (getenv('AGENT_MYSQL_PORT') ?: 3306),
            getenv('AGENT_MYSQL_USER') ?: 'root',
            (string) (getenv('AGENT_MYSQL_PASSWORD') ?: ''),
            getenv('AGENT_MYSQL_DATABASE') ?: 'php_agent',
        ));
    }
}
