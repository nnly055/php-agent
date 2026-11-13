<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * 加载 MCP server 配置：全局 ~/.php-agent/mcp.json + 工作区 .mcp.json 两层
 * （格式与 Claude Code / Cursor 兼容），项目同名条目整条覆盖全局条目。
 *
 * 错误语义：文件缺失 = 静默 no-op（大多数用户不配 MCP）；
 * JSON 损坏 = 抛异常（消息标注来源文件，由 McpPlugin 统一警告）；
 * 单条目非法 = 收进 errors 跳过该条（项目非法条目同名覆盖全局合法条目时，
 * errors 胜出：该名不产出 server，不回落全局）。
 */
final class McpConfig
{
    private const DEFAULT_TIMEOUT = 30.0;

    /** 全局配置路径（所有项目共享）；HOME → USERPROFILE → 临时目录兜底 */
    public static function globalPath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();
        return rtrim((string) $home, '/') . '/.php-agent/mcp.json';
    }

    /** @return array{servers: array<string, McpServerSpec>, errors: array<string, string>} */
    public static function load(string $workspace, ?string $globalFile = null): array
    {
        $servers = [];
        $errors = [];
        // 全局层在前，项目层覆盖同名（spec 条目清 error，error 条目清 spec）
        self::merge($servers, $errors, self::parseFile($globalFile ?? self::globalPath()));
        self::merge($servers, $errors, self::parseFile(rtrim($workspace, '/') . '/.mcp.json'));
        return ['servers' => $servers, 'errors' => $errors];
    }

    /**
     * @param array{servers: array<string, McpServerSpec>, errors: array<string, string>} $acc
     * @param array{servers: array<string, McpServerSpec>, errors: array<string, string>} $layer
     */
    private static function merge(array &$acc, array &$errors, array $layer): void
    {
        foreach ($layer['servers'] as $name => $spec) {
            unset($errors[$name]);
            $acc[$name] = $spec;
        }
        foreach ($layer['errors'] as $name => $err) {
            unset($acc[$name]);
            $errors[$name] = $err;
        }
    }

    /** @return array{servers: array<string, McpServerSpec>, errors: array<string, string>} */
    private static function parseFile(string $file): array
    {
        if (!is_file($file)) {
            return ['servers' => [], 'errors' => []];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("{$file} 不是合法 JSON");
        }

        $servers = [];
        $errors = [];
        foreach ($decoded['mcpServers'] ?? [] as $name => $entry) {
            $name = (string) $name;
            if (!is_array($entry)) {
                $errors[$name] = '条目必须是对象';
                continue;
            }
            $hasCommand = isset($entry['command']) && is_string($entry['command']) && $entry['command'] !== '';
            $hasUrl = isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== '';
            if ($hasCommand === $hasUrl) {
                $errors[$name] = 'command 与 url 必须二选一';
                continue;
            }
            if ($hasCommand) {
                $args = [];
                foreach ((array) ($entry['args'] ?? []) as $a) {
                    $args[] = (string) $a;
                }
                $env = [];
                foreach ($entry['env'] ?? [] as $k => $v) {
                    if (is_string($k)) {
                        $env[$k] = (string) $v;
                    }
                }
                $config = ['command' => $entry['command'], 'args' => $args, 'env' => $env];
                $type = 'stdio';
            } else {
                $headers = [];
                foreach ($entry['headers'] ?? [] as $k => $v) {
                    if (is_string($k)) {
                        $headers[$k] = (string) $v;
                    }
                }
                $config = ['url' => $entry['url'], 'headers' => $headers];
                $type = 'http';
            }
            $timeout = isset($entry['timeout']) && is_numeric($entry['timeout'])
                ? max(1.0, (float) $entry['timeout'])
                : self::DEFAULT_TIMEOUT;
            $servers[$name] = new McpServerSpec($name, $type, $config, $timeout);
        }
        return ['servers' => $servers, 'errors' => $errors];
    }
}
