<?php

declare(strict_types=1);

namespace PhpAgent\Session;

use PhpAgent\Config;

/**
 * 会话存储目录的统一解析：AGENT_SESSION_DIR 优先，默认 ~/.php-agent/sessions。
 */
final class SessionPaths
{
    public static function storageDir(Config $config): string
    {
        $dir = $config->sessionDir();
        if ($dir === null || $dir === '') {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
            $dir = ($home ?: sys_get_temp_dir()) . '/.php-agent/sessions';
        }
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException("无法创建会话存储目录: {$dir}");
        }
        return rtrim($dir, '/');
    }
}
