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
}
