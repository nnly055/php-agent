<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

/**
 * 插件脚手架：生成 plugins/<name>/ 骨架（plugin.php + 示例工具 + 示例命令）。
 * 生成即自动启用（PluginManager 的项目插件发现会扫到 plugin.php）。
 */
final class PluginScaffolder
{
    public static function create(string $root, string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(
                "无效插件名 '{$name}'：须以小写字母开头，只含小写字母/数字/中划线/下划线"
            );
        }

        $dir = $root . '/plugins/' . $name;
        if (is_dir($dir)) {
            throw new \RuntimeException("插件目录已存在，拒绝覆盖: {$dir}");
        }

        $camel = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
        $placeholders = ['{{NAME}}' => $name, '{{CAMEL}}' => $camel];

        if (!mkdir($dir . '/src', 0777, true)) {
            throw new \RuntimeException("无法创建目录: {$dir}/src");
        }

        $pluginPhp = <<<PHP
<?php

// 插件 {$name} —— 由脚手架生成（plugins/{$name}/plugin.php 会被自动发现并加载）
// 临时禁用：重命名为 plugin.php.disabled，或设置 AGENT_PLUGINS=off

require_once __DIR__ . '/src/{$camel}Plugin.php';

return PhpAgent\\Plugin\\{$camel}\\{$camel}Plugin::class;

PHP;

        $src = strtr(<<<'SRC'
<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\{{CAMEL}};

use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Tools\WorkspaceTool;

/**
 * {{NAME}} 插件（脚手架生成）。
 * 演示两个扩展点：工具（模型调用）与斜杠命令（用户触发）。
 * 删掉不需要的部分，换成你的实现；可用扩展点见 docs/plugin-design.md。
 */
final class {{CAMEL}}Plugin implements Plugin
{
    public function name(): string
    {
        return '{{NAME}}';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function register(AgentKernel $kernel): void
    {
        // 扩展点 1：工具 —— 模型通过 function calling 调用（/tools 查看）
        $kernel->registerTool(new class ($kernel->config()->workspace()) extends WorkspaceTool {
            public function name(): string
            {
                return '{{NAME}}_greet';
            }

            public function description(): string
            {
                return '{{NAME}} 插件示例工具：返回一句问候（换成你的实现）';
            }

            public function schema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'who' => ['type' => 'string', 'description' => '问候对象，默认 world'],
                    ],
                ];
            }

            public function execute(array $input): string
            {
                return 'hello ' . ((string) ($input['who'] ?? 'world'));
            }
        });

        // 扩展点 2：斜杠命令 —— CLI 里输入 /{{NAME}} 触发
        $kernel->registerCommand('{{NAME}}', new class implements Command {
            public function description(): string
            {
                return '{{NAME}} 插件示例命令';
            }

            public function execute(array $args, CommandContext $ctx): string
            {
                return '{{NAME}} 插件已加载（会话: ' . $ctx->sessionId . '）';
            }
        });
    }
}
SRC, $placeholders);

        if (file_put_contents($dir . '/plugin.php', $pluginPhp) === false
            || file_put_contents($dir . '/src/' . $camel . 'Plugin.php', $src) === false) {
            throw new \RuntimeException("插件文件写入失败: {$dir}");
        }

        return <<<TXT
✓ 插件 '{$name}' 已生成:
    {$dir}/plugin.php
    {$dir}/src/{$camel}Plugin.php
已自动启用：/tools 可看到 {$name}_greet 工具，/help 可看到 /{$name} 命令
禁用方式：重命名 plugin.php → plugin.php.disabled，或 AGENT_PLUGINS=off

TXT;
    }
}
