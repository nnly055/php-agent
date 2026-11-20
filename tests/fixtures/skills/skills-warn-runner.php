<?php

declare(strict_types=1);

/**
 * 测试夹具：子进程里装配 SkillsPlugin，捕获其 STDERR 输出（STDERR 常量进程内不可重定向）。
 * PHP_AGENT_TEST_ROOT = 临时项目根（自带 .env）。结束时打印 registry 状态供断言。
 */

use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;

require getenv('PHP_AGENT_ROOT') . '/vendor/autoload.php';

$root = getenv('PHP_AGENT_TEST_ROOT') ?: '';
$kernel = new AgentKernel(Config::load($root));
PluginManager::load($kernel, $root);
fwrite(STDERR, 'REGISTRY=' . ($kernel->skillRegistry() === null ? 'null' : 'set') . "\n");
