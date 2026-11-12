<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-agent-config-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
    }

    private function configWith(string $envContent): Config
    {
        file_put_contents($this->dir . '/.env', $envContent);
        return Config::load($this->dir);
    }

    public function testCompactionDefaults(): void
    {
        $c = $this->configWith("AGENT_PROVIDER=openai\n");
        self::assertSame(128000, $c->contextTokens());
        self::assertSame(0.8, $c->compactThreshold());
        self::assertSame('', $c->compactProvider());
    }

    public function testCompactionOverrides(): void
    {
        $c = $this->configWith(
            "AGENT_CONTEXT_TOKENS=32000\nAGENT_COMPACT_THRESHOLD=0.5\nAGENT_COMPACT_PROVIDER=fake\n"
        );
        self::assertSame(32000, $c->contextTokens());
        self::assertSame(0.5, $c->compactThreshold());
        self::assertSame('fake', $c->compactProvider());
    }

    public function testCompactionInvalidFallsBackToDefaults(): void
    {
        $c = $this->configWith("AGENT_CONTEXT_TOKENS=abc\nAGENT_COMPACT_THRESHOLD=1.5\n");
        self::assertSame(128000, $c->contextTokens());
        self::assertSame(0.8, $c->compactThreshold());
    }

    public function testServerPortAllowsZeroForAutoAssign(): void
    {
        self::assertSame(0, $this->configWith("AGENT_SERVER_PORT=0\n")->serverPort());
    }

    public function testServerPortDefaultsTo8091(): void
    {
        self::assertSame(8091, $this->configWith("AGENT_PROVIDER=fake\n")->serverPort());
    }

    public function testVersionConstantExists(): void
    {
        self::assertSame('0.2.0', Config::VERSION);
    }

    public function testDefaultModeEnvFileOverridesShell(): void
    {
        putenv('AGENT_SERVER_PORT=0');
        try {
            // 默认模式：.env 覆盖 shell → 8091
            self::assertSame(8091, $this->configWith("AGENT_SERVER_PORT=8091\n")->serverPort());
        } finally {
            putenv('AGENT_SERVER_PORT');
        }
    }

    public function testShellWinsModeShellOverridesEnvFile(): void
    {
        putenv('AGENT_SERVER_PORT=0');
        try {
            $this->configWith("AGENT_SERVER_PORT=8091\n"); // 写入 .env（默认加载）
            self::assertSame(0, Config::load($this->dir, true)->serverPort()); // shell-wins → 0
        } finally {
            putenv('AGENT_SERVER_PORT');
        }
    }

    public function testShellWinsWorkspaceOverridesEnvFile(): void
    {
        $ws = sys_get_temp_dir() . '/php-agent-ws-' . uniqid();
        mkdir($ws, 0777, true);
        putenv('AGENT_WORKSPACE=' . $ws);
        try {
            $this->configWith("AGENT_WORKSPACE=/nonexistent-xyz\n");
            // workspace() 返回 realpath 解析后的路径（macOS /var → /private/var），故比对 realpath($ws)
            self::assertSame(realpath($ws), Config::load($this->dir, true)->workspace());
        } finally {
            putenv('AGENT_WORKSPACE');
            @rmdir($ws);
        }
    }

    public function testFromArrayBuildsIndependentConfig(): void
    {
        $c = Config::fromArray([
            'AGENT_PROVIDER' => 'anthropic',
            'AGENT_API_KEY' => 'sk-test',
            'AGENT_BASE_URL' => 'https://example.com/',
            'AGENT_MODEL' => 'claude-sonnet-4-5',
            'AGENT_MAX_TOKENS' => '16',
        ]);
        self::assertSame('anthropic', $c->provider());
        self::assertSame('sk-test', $c->apiKey());
        self::assertSame('https://example.com', $c->baseUrl());
        self::assertSame('claude-sonnet-4-5', $c->model());
        // maxTokens 有 max(1024, …) 下限：传 16 实际生效 1024（压低默认 8192，即 /config/test 的成本控制手段）
        self::assertSame(1024, $c->maxTokens());
    }

    public function testFromArrayEmptyFallsBackToDefaults(): void
    {
        $c = Config::fromArray([]);
        self::assertSame('openai', $c->provider());
        self::assertSame('https://api.openai.com/v1', $c->baseUrl());
        self::assertSame('', $c->model());
    }
}
