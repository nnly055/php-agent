<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

use PhpAgent\Config;
use PhpAgent\Plugins\AnthropicPlugin;
use PhpAgent\Plugins\CommandsPlugin;
use PhpAgent\Plugins\CoreToolsPlugin;
use PhpAgent\Plugins\FakePlugin;
use PhpAgent\Plugins\OpenAICompatPlugin;
use PhpAgent\Plugins\SessionPlugin;

/**
 * 插件发现与加载：
 *   1. 内置插件（固定清单）
 *   2. 项目 plugins/{name}/plugin.php（返回插件类名），可用 AGENT_PLUGINS 过滤
 *   3. composer extra 发现（Phase 3 接入）
 */
final class PluginManager
{
    /** @return Plugin[] 按加载顺序返回 */
    public static function load(AgentKernel $kernel, string $root): array
    {
        $loaded = [];

        foreach (self::builtin() as $class) {
            $plugin = new $class();
            $kernel->notePlugin($plugin->name(), $plugin->version());
            $plugin->register($kernel);
            $loaded[] = $plugin;
        }

        if ($kernel->config()->pluginsSwitch() !== 'off') {
            foreach (self::discover($root) as $plugin) {
                $filter = self::filterList($kernel->config()->pluginsSwitch());
                if ($filter !== null && !in_array($plugin->name(), $filter, true)) {
                    continue;
                }
                $kernel->notePlugin($plugin->name(), $plugin->version());
                $plugin->register($kernel);
                $loaded[] = $plugin;
            }
        }

        return $loaded;
    }

    /** @return string[] */
    private static function builtin(): array
    {
        return [
            CoreToolsPlugin::class,
            AnthropicPlugin::class,
            OpenAICompatPlugin::class,
            FakePlugin::class,
            SessionPlugin::class,
            CommandsPlugin::class,
        ];
    }

    /** null=不过滤；数组=名字白名单 */
    private static function filterList(string $switch): ?array
    {
        if ($switch === '' || $switch === 'on') {
            return null;
        }
        return array_values(array_filter(array_map('trim', explode(',', $switch))));
    }

    /** @return Plugin[] */
    private static function discover(string $root): array
    {
        $plugins = [];
        foreach (glob($root . '/plugins/*/plugin.php') ?: [] as $file) {
            $class = require $file;
            if (is_string($class) && class_exists($class) && is_subclass_of($class, Plugin::class)) {
                $plugins[] = new $class();
            } else {
                fwrite(STDERR, "⚠ 插件文件未返回有效 Plugin 类: {$file}\n");
            }
        }
        return $plugins;
    }
}
