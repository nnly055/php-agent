<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Command\Command;
use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\Session\SessionStore;
use PHPUnit\Framework\TestCase;

/**
 * 插件加载：内置清单、provider 惰性/覆盖语义、项目插件发现与过滤。
 */
final class PluginManagerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        putenv('AGENT_PLUGINS'); // 清理测试期间设置的环境变量
    }

    public function testBuiltinPluginsLoad(): void
    {
        $kernel = new AgentKernel(Config::load($this->projectRoot));
        $plugins = PluginManager::load($kernel, $this->projectRoot);

        self::assertGreaterThanOrEqual(6, count($plugins));

        // 五件套工具就位
        foreach (['read_file', 'write_file', 'list_dir', 'run_command', 'grep'] as $t) {
            self::assertContains($t, $kernel->toolRegistry()->names());
        }

        // 三个 provider 注册（惰性，未实例化）
        foreach (['anthropic', 'openai', 'fake'] as $id) {
            self::assertTrue($kernel->hasProvider($id), "provider={$id}");
        }

        // 内置命令 + 会话存储就位
        foreach (['help', 'tools', 'plugins', 'sessions', 'resume', 'new'] as $c) {
            self::assertInstanceOf(Command::class, $kernel->command($c), "command={$c}");
        }
        self::assertInstanceOf(SessionStore::class, $kernel->sessionStore());

        // 插件清单记录
        self::assertArrayHasKey('core-tools', $kernel->plugins());
        self::assertArrayHasKey('session', $kernel->plugins());
    }

    public function testProviderIsLazyAndOverridable(): void
    {
        $kernel = new AgentKernel(Config::load($this->projectRoot));

        $calls = 0;
        $kernel->registerProvider('x', static function () use (&$calls) {
            $calls++;
            return FakeLLMClient::demo();
        });
        $a = $kernel->provider('x');
        $b = $kernel->provider('x');
        self::assertSame(1, $calls, '惰性工厂只应实例化一次');
        self::assertSame($a, $b, '缓存同一实例');

        $calls2 = 0;
        $kernel->registerProvider('x', static function () use (&$calls2) {
            $calls2++;
            return FakeLLMClient::demo();
        });
        $c = $kernel->provider('x');
        self::assertNotSame($a, $c, '覆盖后应得到新实例');
        self::assertSame(1, $calls2);
    }

    public function testProjectPluginDiscoveryAndOffSwitch(): void
    {
        $fixturesRoot = $this->projectRoot . '/tests/fixtures';

        // 默认：全部加载，fixture 插件注册的 fixture_tool 就位
        putenv('AGENT_PLUGINS=');
        $kernel = new AgentKernel(Config::load($fixturesRoot));
        PluginManager::load($kernel, $fixturesRoot);
        self::assertContains('fixture_tool', $kernel->toolRegistry()->names());
        self::assertArrayHasKey('fixture', $kernel->plugins());

        // off：只加载内置
        putenv('AGENT_PLUGINS=off');
        $kernel2 = new AgentKernel(Config::load($fixturesRoot));
        PluginManager::load($kernel2, $fixturesRoot);
        self::assertNotContains('fixture_tool', $kernel2->toolRegistry()->names());
        self::assertContains('read_file', $kernel2->toolRegistry()->names());

        // 白名单：只加载名字匹配的项目插件
        putenv('AGENT_PLUGINS=fixture');
        $kernel3 = new AgentKernel(Config::load($fixturesRoot));
        PluginManager::load($kernel3, $fixturesRoot);
        self::assertContains('fixture_tool', $kernel3->toolRegistry()->names());
    }
}
