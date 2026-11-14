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
        (new \PhpAgent\Plugins\CommandsPlugin())->register($this->kernel);

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

    // ── /model 命令 ────────────────────────────────────────────

    /** @return array<int, string> 输出行 */
    private function runModel(array $args): array
    {
        $out = $this->kernel->command('model')->execute($args, $this->ctx);
        return explode("\n", $out);
    }

    public function testModelListsCatalogWithActiveMark(): void
    {
        // selection 为 null → 默认档 glm 的首模型标 ✓
        $lines = $this->runModel([]);
        $glmL = $lines[1] ?? '';
        self::assertStringContainsString('✓', $glmL);
        self::assertStringContainsString('glm', $glmL);
        self::assertStringContainsString('glm-4.6', $glmL);
        self::assertStringContainsString('openai 兼容', $glmL);
        self::assertStringNotContainsString('✓', $lines[2] ?? '');
        self::assertStringContainsString('anthropic 原生', $lines[2] ?? '');
    }

    public function testModelMarkFollowsSelection(): void
    {
        $this->kernel->command('model')->execute(['cl'], $this->ctx);
        $lines = $this->runModel([]);
        self::assertStringNotContainsString('✓', $lines[1] ?? '');
        self::assertStringContainsString('✓', $lines[2] ?? '');
        self::assertStringContainsString('claude-sonnet-4-5', $lines[2] ?? '');
    }

    public function testModelSwitchWritesSelectionAndReplacesClient(): void
    {
        $startClient = $this->kernel->loop()->client();
        $out = $this->kernel->command('model')->execute(['cl', 'claude-sonnet-4-5'], $this->ctx);
        self::assertSame(['profileId' => 'cl', 'model' => 'claude-sonnet-4-5', 'effort' => null], $this->ctx->selection);
        self::assertNotSame($startClient, $this->kernel->loop()->client(), 'setClient 生效');
        self::assertSame($this->kernel->loop()->client(), $this->kernel->profileRegistry()->activeClient(), 'compact 跟随当前生效模型');
        self::assertStringContainsString('anthropic', $out);
        self::assertStringContainsString('回放', $out); // 跨协议提示（openai → anthropic）
    }

    public function testModelSwitchSameProviderNoReplayHint(): void
    {
        $out = $this->kernel->command('model')->execute(['glm', 'glm-4.5-air'], $this->ctx);
        self::assertSame(['profileId' => 'glm', 'model' => 'glm-4.5-air', 'effort' => null], $this->ctx->selection);
        self::assertStringNotContainsString('回放', $out); // 同协议（openai → openai）
    }

    public function testModelSwitchProfileOnlyFallsToFirstModel(): void
    {
        $this->kernel->command('model')->execute(['cl'], $this->ctx);
        self::assertSame(['profileId' => 'cl', 'model' => 'claude-sonnet-4-5', 'effort' => null], $this->ctx->selection);
    }

    public function testModelSwitchAllowsUncataloguedModel(): void
    {
        $this->kernel->command('model')->execute(['glm', 'glm-4.5v'], $this->ctx);
        self::assertSame(['profileId' => 'glm', 'model' => 'glm-4.5v', 'effort' => null], $this->ctx->selection);
    }

    public function testModelRejectsUnknownProfile(): void
    {
        $out = $this->kernel->command('model')->execute(['nope'], $this->ctx);
        self::assertStringContainsString('未知 profile', $out);
        self::assertNull($this->ctx->selection, '失败切换不得写脏 selection');
        self::assertSame(FakeLLMClient::class, get_class($this->kernel->loop()->client()), 'client 不变');
    }

    public function testModelWithoutRegistryErrors(): void
    {
        $k = new AgentKernel(Config::fromArray(['AGENT_SESSION_STORE' => 'memory']));
        (new \PhpAgent\Plugins\CommandsPlugin())->register($k);
        $messages = []; $sid = 's'; $sel = null;
        $out = $k->command('model')->execute([], new CommandContext($k, $messages, $sid, $sel));
        self::assertStringContainsString('不可用', $out);
    }

    // ── /effort 命令 + /model 输出附强度 ───────────────────────

    public function testEffortCommandSetsSelectionPreservingProfile(): void
    {
        $this->kernel->command('model')->execute(['cl', 'claude-sonnet-4-5'], $this->ctx);
        $out = $this->kernel->command('effort')->execute(['max'], $this->ctx);
        self::assertSame(['profileId' => 'cl', 'model' => 'claude-sonnet-4-5', 'effort' => 'max'], $this->ctx->selection);
        self::assertStringContainsString('max', $out);
    }

    public function testEffortCommandRejectsUnknownValue(): void
    {
        $out = $this->kernel->command('effort')->execute(['turbo'], $this->ctx);
        self::assertStringContainsString('off|low|medium|high|max', $out);
        self::assertNull($this->ctx->selection);
    }

    public function testEffortCommandShowsCurrent(): void
    {
        $out = $this->kernel->command('effort')->execute([], $this->ctx);
        self::assertStringContainsString('off', $out);
    }

    public function testModelCatalogShowsEffort(): void
    {
        $out = $this->kernel->command('model')->execute([], $this->ctx);
        self::assertStringContainsString('思考强度', $out);
        self::assertStringContainsString('off', $out);
    }
}
