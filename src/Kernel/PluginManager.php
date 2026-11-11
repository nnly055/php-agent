<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

use PhpAgent\Plugins\AnthropicPlugin;
use PhpAgent\Plugins\AuditPlugin;
use PhpAgent\Plugins\CommandsPlugin;
use PhpAgent\Plugins\CoreToolsPlugin;
use PhpAgent\Plugins\FakePlugin;
use PhpAgent\Plugins\OpenAICompatPlugin;
use PhpAgent\Plugins\SessionPlugin;

/**
 * 插件发现与加载（三层，后注册者覆盖同 id）：
 *   1. 内置插件（固定清单，始终加载）
 *   2. composer 包插件（extra.php-agent 声明，见 ComposerPluginDiscovery）
 *   3. 项目 plugins/{name}/plugin.php（返回插件类名）
 *
 * AGENT_PLUGINS：off = 只加载内置；逗号列表 = 白名单过滤 2/3 层；留空/on = 全部加载。
 */
final class PluginManager
{
    /** @return Plugin[] 按加载顺序返回 */
    public static function load(AgentKernel $kernel, string $root): array
    {
        $loaded = [];

        foreach (self::builtin() as $class) {
            self::loadOne($kernel, new $class(), null, $loaded);
        }

        $switch = $kernel->config()->pluginsSwitch();
        if ($switch !== 'off') {
            $filter = self::filterList($switch);
            foreach (ComposerPluginDiscovery::discover($root) as $plugin) {
                self::loadOne($kernel, $plugin, $filter, $loaded);
            }
            foreach (self::discoverProject($root) as $plugin) {
                self::loadOne($kernel, $plugin, $filter, $loaded);
            }
        }

        return $loaded;
    }

    private static function loadOne(AgentKernel $kernel, Plugin $plugin, ?array $filter, array &$loaded): void
    {
        if ($filter !== null && !in_array($plugin->name(), $filter, true)) {
            return;
        }
        $kernel->notePlugin($plugin->name(), $plugin->version());
        $plugin->register($kernel);
        $loaded[] = $plugin;
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
            AuditPlugin::class,
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
    private static function discoverProject(string $root): array
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
