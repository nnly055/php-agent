<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

/**
 * Composer 包插件发现：
 * 第三方包在自家 composer.json 里声明
 *   "extra": { "php-agent": { "plugins": ["Vendor\\Plugin\\Class"] } }
 * composer install 后信息落在 vendor/composer/installed.json，这里直接读取
 * （不依赖 Composer\InstalledVersions，无 composer 运行时也能工作）。
 *
 * 类加载依赖 composer 把第三方包的 autoload 合并进根 autoloader——正常使用即满足。
 */
final class ComposerPluginDiscovery
{
    /** @return Plugin[] */
    public static function discover(string $root): array
    {
        $file = $root . '/vendor/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw)) {
            fwrite(STDERR, "⚠ composer installed.json 解析失败，跳过 composer 插件发现: {$file}\n");
            return [];
        }

        // Composer 2.2+ 包在 "packages" 键下；更早版本是裸数组
        $packages = $raw['packages'] ?? (array_is_list($raw) ? $raw : []);

        $plugins = [];
        foreach ($packages as $pkg) {
            $classes = $pkg['extra']['php-agent']['plugins'] ?? [];
            foreach ((array) $classes as $class) {
                if (!is_string($class) || $class === '') {
                    continue;
                }
                if (!class_exists($class)) {
                    fwrite(STDERR, "⚠ composer 包插件类未找到（autoload 未覆盖？）: {$class}\n");
                    continue;
                }
                if (!is_subclass_of($class, Plugin::class)) {
                    fwrite(STDERR, "⚠ composer 包插件未实现 Plugin 接口: {$class}\n");
                    continue;
                }
                $plugins[] = new $class();
            }
        }
        return $plugins;
    }
}
