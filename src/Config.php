<?php

declare(strict_types=1);

namespace PhpAgent;

/**
 * 运行时配置：优先读项目根目录 .env，shell 环境变量作为兜底。
 * server 模式（shellWins=true）反转优先级，shell 权威。
 */
final class Config
{
    /** 应用版本（/status 展示用；发版手动 bump，单一来源） */
    public const VERSION = '0.2.0';

    private function __construct(private array $env)
    {
    }

    /**
     * @param bool $shellWins true = shell AGENT_* 环境变量覆盖 .env（桌面 sidecar：壳注入的
     *                        端口/工作目录权威）；false（默认）= .env 覆盖 shell（CLI 行为不变）
     */
    public static function load(string $root, bool $shellWins = false): self
    {
        $shell = [];
        foreach (getenv() as $k => $v) {
            if (str_starts_with($k, 'AGENT_') && $v !== '' && $v !== false) {
                $shell[$k] = (string) $v;
            }
        }

        $fileEnv = [];
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
                    $fileEnv[$k] = $v;
                }
            }
        }

        $env = $shellWins ? array_merge($fileEnv, $shell) : array_merge($shell, $fileEnv);
        return new self($env);
    }

    /** 用给定 env（AGENT_* 键值映射）构造实例；/config/test 与单测的临时配置入口 */
    public static function fromArray(array $env): self
    {
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
        return max(0, (int) ($this->env['AGENT_SERVER_PORT'] ?? 8091));
    }

    // ── 插件与会话 ──────────────────────────────────────────

    /** 会话存储实现：memory | json | sqlite3 | redis（SessionPlugin 按此装配） */
    public function sessionStore(): string
    {
        $s = strtolower($this->env['AGENT_SESSION_STORE'] ?? 'json');
        return in_array($s, ['memory', 'json', 'sqlite3', 'redis'], true) ? $s : 'json';
    }

    /** CLI 默认会话 id */
    public function sessionId(): string
    {
        $id = trim($this->env['AGENT_SESSION_ID'] ?? 'default');
        return $id !== '' ? $id : 'default';
    }

    /** 会话存储目录（json/sqlite3 用），null = ~/.php-agent/sessions */
    public function sessionDir(): ?string
    {
        return $this->env['AGENT_SESSION_DIR'] ?? null;
    }

    public function redisHost(): string
    {
        return $this->env['AGENT_REDIS_HOST'] ?? '127.0.0.1';
    }

    public function redisPort(): int
    {
        return max(1, (int) ($this->env['AGENT_REDIS_PORT'] ?? 6379));
    }

    public function redisDb(): int
    {
        return max(0, (int) ($this->env['AGENT_REDIS_DB'] ?? 0));
    }

    public function redisPassword(): ?string
    {
        return ($this->env['AGENT_REDIS_PASSWORD'] ?? '') ?: null;
    }

    /**
     * 插件加载开关：
     *   '' / on = 全部加载（内置 + composer + 项目 plugins/）
     *   off     = 只加载内置
     *   逗号列表 = 内置 + 仅列表中的项目插件（按 name 匹配）
     */
    public function pluginsSwitch(): string
    {
        return strtolower(trim($this->env['AGENT_PLUGINS'] ?? ''));
    }

    /** MCP 客户端开关：on（默认）| off（不加载 McpPlugin、不连任何 server） */
    public function mcpEnabled(): bool
    {
        return strtolower(trim($this->env['AGENT_MCP'] ?? 'on')) !== 'off';
    }

    // ── 权限与审计 ──────────────────────────────────────────

    /**
     * 工具执行权限：ask（默认）= 写文件/执行命令前 CLI 交互确认，只读工具自动放行；
     * off = 全部放行。server 端不做交互确认（桌面壳自行实现前端授权）。
     */
    public function permissions(): string
    {
        $p = strtolower($this->env['AGENT_PERMISSIONS'] ?? 'ask');
        return in_array($p, ['off', 'ask'], true) ? $p : 'ask';
    }

    /** 审计日志开关（默认 on，写入 <存储目录>/audit.jsonl） */
    public function audit(): bool
    {
        return strtolower($this->env['AGENT_AUDIT'] ?? 'on') !== 'off';
    }

    /** 审计文件目录，null = 会话存储目录 */
    public function auditDir(): ?string
    {
        return $this->env['AGENT_AUDIT_DIR'] ?? null;
    }

    // ── 上下文压缩（compact 插件）────────────────────────────

    /** 模型上下文窗口 token 数（自动压缩阈值基数），按所用模型在 .env 调整 */
    public function contextTokens(): int
    {
        $v = filter_var($this->env['AGENT_CONTEXT_TOKENS'] ?? null, FILTER_VALIDATE_INT);
        return ($v !== false && $v > 0) ? $v : 128000;
    }

    /** 自动压缩阈值：估算 token 达到窗口该比例时触发（0 < t ≤ 1，越界回落默认） */
    public function compactThreshold(): float
    {
        $v = filter_var($this->env['AGENT_COMPACT_THRESHOLD'] ?? null, FILTER_VALIDATE_FLOAT);
        return ($v !== false && $v > 0 && $v <= 1) ? (float) $v : 0.8;
    }

    /** 压缩摘要请求所用 provider id，空 = AGENT_PROVIDER；--fake 离线演示设为 fake */
    public function compactProvider(): string
    {
        return trim($this->env['AGENT_COMPACT_PROVIDER'] ?? '');
    }
}
