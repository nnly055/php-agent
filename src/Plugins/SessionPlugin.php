<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Session\SessionPaths;
use PhpAgent\Session\Store\InMemorySessionStore;
use PhpAgent\Session\Store\JsonFileSessionStore;
use PhpAgent\Session\Store\RedisSessionStore;
use PhpAgent\Session\SessionStore;
use PhpAgent\Session\Store\Sqlite3SessionStore;

/**
 * 会话持久化插件：按 AGENT_SESSION_STORE 选择实现（memory|json|sqlite3|redis）。
 * 第三方插件也可以直接 setSessionStore() 覆盖——只需声明更晚的加载顺序。
 */
final class SessionPlugin implements Plugin
{
    public function name(): string
    {
        return 'session';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $config = $kernel->config();
        $store = match ($config->sessionStore()) {
            'memory' => new InMemorySessionStore(),
            'sqlite3' => new Sqlite3SessionStore(SessionPaths::storageDir($config)),
            'redis' => new RedisSessionStore(
                $config->redisHost(),
                $config->redisPort(),
                $config->redisDb(),
                $config->redisPassword()
            ),
            'json' => new JsonFileSessionStore(SessionPaths::storageDir($config)),
        };
        $kernel->setSessionStore($store);
    }
}
