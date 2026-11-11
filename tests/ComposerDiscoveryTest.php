<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\ComposerPluginDiscovery;
use PhpAgent\Kernel\PluginManager;
use PhpAgent\Kernel\PluginScaffolder;
use PhpAgent\LLM\LLMClient;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3：composer 包发现 + 插件脚手架。
 */
final class ComposerDiscoveryTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        putenv('AGENT_PLUGINS');
    }

    public function testDiscoverFromWrappedInstalledJson(): void
    {
        $root = $this->projectRoot . '/tests/fixtures/composer-test';
        $plugins = ComposerPluginDiscovery::discover($root);
        self::assertCount(1, $plugins, 'extra 为空的包应被跳过');
        self::assertSame('testvendor', $plugins[0]->name());
    }

    public function testDiscoverFromLegacyBareArray(): void
    {
        $plugins = ComposerPluginDiscovery::discover($this->projectRoot . '/tests/fixtures/composer-legacy');
        self::assertCount(1, $plugins);
        self::assertSame('testvendor', $plugins[0]->name());
    }

    public function testMalformedJsonReturnsEmpty(): void
    {
        $plugins = ComposerPluginDiscovery::discover($this->projectRoot . '/tests/fixtures/composer-broken');
        self::assertSame([], $plugins);
    }

    public function testMissingVendorReturnsEmpty(): void
    {
        self::assertSame([], ComposerPluginDiscovery::discover('/nonexistent-root'));
    }

    public function testRealVendorHasNoPhpAgentExtra(): void
    {
        // 项目自身的 vendor（phpunit 等）没有 php-agent 声明 → 空结果、无异常
        self::assertSame([], ComposerPluginDiscovery::discover($this->projectRoot));
    }

    public function testComposerPluginLoadsThroughPluginManager(): void
    {
        putenv('AGENT_PLUGINS=');
        $root = $this->projectRoot . '/tests/fixtures/composer-test';
        $kernel = new AgentKernel(Config::load($root));
        $loaded = PluginManager::load($kernel, $root);

        self::assertSame('1.0.0', $kernel->plugins()['testvendor'] ?? null, 'composer 插件已注册');
        self::assertContains('vendor_tool', $kernel->toolRegistry()->names());
        self::assertTrue($kernel->hasProvider('vendor'), 'composer 包可注册 provider');
        self::assertInstanceOf(LLMClient::class, $kernel->provider('vendor'));

        // 内置仍在
        self::assertContains('read_file', $kernel->toolRegistry()->names());

        // 三层顺序：composer 层先于项目层（后者可覆盖前者，即覆盖语义的保证）
        $names = array_map(static fn ($p) => $p->name(), $loaded);
        $iVendor = array_search('testvendor', $names);
        $iOverride = array_search('overrider', $names);
        self::assertIsInt($iVendor);
        self::assertIsInt($iOverride);
        self::assertLessThan($iOverride, $iVendor, 'composer 插件先于项目插件加载');
    }

    public function testOffSwitchSkipsComposerPlugins(): void
    {
        putenv('AGENT_PLUGINS=off');
        $root = $this->projectRoot . '/tests/fixtures/composer-test';
        $kernel = new AgentKernel(Config::load($root));
        PluginManager::load($kernel, $root);
        self::assertNotContains('vendor_tool', $kernel->toolRegistry()->names());
        self::assertNotContains('testvendor', $kernel->plugins());
    }

    public function testWhitelistFiltersComposerPlugins(): void
    {
        putenv('AGENT_PLUGINS=other-name');
        $root = $this->projectRoot . '/tests/fixtures/composer-test';
        $kernel = new AgentKernel(Config::load($root));
        PluginManager::load($kernel, $root);
        self::assertNotContains('vendor_tool', $kernel->toolRegistry()->names());

        putenv('AGENT_PLUGINS=testvendor');
        $kernel2 = new AgentKernel(Config::load($root));
        PluginManager::load($kernel2, $root);
        self::assertContains('vendor_tool', $kernel2->toolRegistry()->names());
    }
}

final class PluginScaffolderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php-agent-scaffold-' . uniqid();
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/plugins/*/*/plugin.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->root . '/plugins/*/src/*.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->root . '/plugins/*/src') ?: [] as $f) {
            @rmdir($f);
        }
        foreach (glob($this->root . '/plugins/*') ?: [] as $f) {
            @rmdir($f);
        }
        @rmdir($this->root . '/plugins');
        @rmdir($this->root);
    }

    public function testScaffoldCreatesLoadablePlugin(): void
    {
        // 名字含 uniqid，避免多次运行时同名类重复声明
        $name = 'demo' . strtolower(uniqid());
        $message = PluginScaffolder::create($this->root, $name);

        self::assertStringContainsString('已生成', $message);

        $dir = $this->root . '/plugins/' . $name;
        self::assertFileExists($dir . '/plugin.php');
        $srcFiles = glob($dir . '/src/*.php') ?: [];
        self::assertCount(1, $srcFiles);

        // plugin.php 返回类名且可实例化注册
        $class = require $dir . '/plugin.php';
        self::assertTrue(class_exists($class));

        $kernel = new AgentKernel(Config::load($this->root));
        (new $class())->register($kernel);
        self::assertContains($name . '_greet', $kernel->toolRegistry()->names());
        self::assertNotNull($kernel->command($name));
        self::assertSame('0.1.0', $kernel->plugins()[$name] ?? null);
    }

    public function testScaffoldRejectsInvalidNames(): void
    {
        foreach (['My-Tool', '1abc', 'a b', '包含中文', ''] as $bad) {
            try {
                PluginScaffolder::create($this->root, $bad);
                self::fail("应拒绝无效名: {$bad}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('无效插件名', $e->getMessage());
            }
        }
    }

    public function testScaffoldRefusesOverwrite(): void
    {
        PluginScaffolder::create($this->root, 'dupe-tool');
        try {
            PluginScaffolder::create($this->root, 'dupe-tool');
            self::fail('重复创建应抛异常');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('已存在', $e->getMessage());
        }
    }
}
