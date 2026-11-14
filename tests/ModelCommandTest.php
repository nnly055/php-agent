<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Command\CommandContext;
use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\ProfileRegistry;
use PhpAgent\LLM\FakeLLMClient;
use PHPUnit\Framework\TestCase;

/**
 * 会话内切换模型（CLI /model）：plumbing（kernel loop 注册 / context selection）+
 * /model 命令行为。fake provider 离线验证，不依赖真实端点。
 */
final class ModelCommandTest extends TestCase
{
    private AgentKernel $kernel;
    private AgentLoop $loop;
    private array $messages = [];
    private string $sessionId = 's1';
    private ?array $selection = null;
    private CommandContext $ctx;

    protected function setUp(): void
    {
        $this->kernel = new AgentKernel(Config::fromArray([
            'AGENT_PROVIDER' => 'fake',
            'AGENT_SESSION_STORE' => 'memory',
        ]));
        $this->kernel->registerProvider('fake', static fn () => new FakeLLMClient([]));

        $registry = new ProfileRegistry($this->kernel);
        // 两档目录：glm（openai 兼容）与 cl（anthropic 原生）——跨协议切换提示用
        $registry->setProfiles([
            ['id' => 'glm', 'name' => '智谱', 'provider' => 'openai', 'baseUrl' => 'https://x/v4', 'apiKey' => 'k1', 'models' => ['glm-4.6', 'glm-4.5-air']],
            ['id' => 'cl', 'name' => 'Anthropic', 'provider' => 'anthropic', 'baseUrl' => 'https://y', 'apiKey' => 'k2', 'models' => ['claude-sonnet-4-5']],
        ], 'glm');
        $this->kernel->setProfileRegistry($registry);

        $this->loop = new AgentLoop(new FakeLLMClient([]), $this->kernel->toolRegistry(), 'sys', 5, []);
        $this->kernel->setLoop($this->loop);

        $this->ctx = new CommandContext($this->kernel, $this->messages, $this->sessionId, $this->selection);
    }

    public function testLoopUnregisteredThrows(): void
    {
        $k = new AgentKernel(Config::fromArray([]));
        $this->expectException(\RuntimeException::class);
        $k->loop();
    }

    public function testLoopRegisteredAndClientAccessor(): void
    {
        self::assertSame($this->loop, $this->kernel->loop());
        self::assertSame(FakeLLMClient::class, get_class($this->loop->client()));
    }

    public function testContextSelectionWriteThrough(): void
    {
        $this->ctx->selection = ['profileId' => 'p', 'model' => 'm'];
        self::assertSame(['profileId' => 'p', 'model' => 'm'], $this->selection); // 引用写穿
    }
}
