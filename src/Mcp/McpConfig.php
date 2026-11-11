<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * 加载工作区根目录的 .mcp.json（格式与 Claude Code / Cursor 兼容）。
 *
 * 错误语义：文件缺失 = 静默 no-op（大多数用户不配 MCP）；
 * JSON 损坏 = 抛异常（由 McpPlugin 统一警告）；单条目非法 = 收进 errors 跳过该条。
 */
final class McpConfig
{
    private const DEFAULT_TIMEOUT = 30.0;

    /** @return array{servers: array<string, McpServerSpec>, errors: array<string, string>} */
    public static function load(string $workspace): array
    {
        $file = rtrim($workspace, '/') . '/.mcp.json';
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
