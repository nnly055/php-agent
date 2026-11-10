<?php

declare(strict_types=1);

namespace PhpAgent;

/**
 * 运行时配置：优先读项目根目录 .env，shell 环境变量作为兜底。
 */
final class Config
{
    private function __construct(private array $env)
    {
    }

    public static function load(string $root): self
    {
        $env = [];

        // shell / 进程环境变量兜底
        foreach (getenv() as $k => $v) {
            if (str_starts_with($k, 'AGENT_') && $v !== '' && $v !== false) {
                $env[$k] = (string) $v;
            }
        }

        // .env 覆盖 shell（本地文件是最显式的声明）
        $file = $root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                if ($k !== '') {
                    $env[$k] = $v;
                }
            }
        }

        return new self($env);
    }

    /** provider：openai（兼容协议，覆盖 GLM/DeepSeek/Kimi/OpenAI 等）| anthropic | fake */
    public function provider(): string
    {
        $p = strtolower($this->env['AGENT_PROVIDER'] ?? 'openai');
        return in_array($p, ['openai', 'anthropic', 'fake'], true) ? $p : 'openai';
    }

    public function apiKey(): string
    {
        return $this->env['AGENT_API_KEY'] ?? '';
    }

    /** 不含尾斜杠的 base URL，各客户端自行拼接接口路径 */
    public function baseUrl(): string
    {
        $url = rtrim($this->env['AGENT_BASE_URL'] ?? '', '/');
        if ($url !== '') {
            return $url;
        }
        return $this->provider() === 'anthropic'
            ? 'https://api.anthropic.com'
            : 'https://api.openai.com/v1';
    }

    public function model(): string
    {
        return $this->env['AGENT_MODEL'] ?? '';
    }

    public function maxTokens(): int
    {
        return max(1024, (int) ($this->env['AGENT_MAX_TOKENS'] ?? 8192));
    }

    public function maxIterations(): int
    {
        return max(1, (int) ($this->env['AGENT_MAX_ITERATIONS'] ?? 25));
    }

    /** 工具操作的工作目录（沙箱根） */
    public function workspace(): string
    {
        $w = $this->env['AGENT_WORKSPACE'] ?? '';
        if ($w !== '') {
            $real = realpath($w);
            if ($real === false || !is_dir($real)) {
                throw new \RuntimeException("AGENT_WORKSPACE 目录不存在: {$w}");
            }
            return rtrim($real, '/');
        }
        return rtrim((string) getcwd(), '/');
    }

    public function serverPort(): int
    {
        return max(1, (int) ($this->env['AGENT_SERVER_PORT'] ?? 8091));
    }
}
