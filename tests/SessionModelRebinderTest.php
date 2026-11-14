<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\ProfileRegistry;
use PhpAgent\Kernel\SessionModelRebinder;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\Session\Store\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

/**
 * SessionModelRebinder：会话切换（/resume、/new）后按 meta 重绑模型。
 * 语义：有选择 → resolve+setClient 返回 selection；profile 已删 → 回落默认档返回 null；
 * 无选择/会话不在列表 → 默认档返回 null（save 传 null 不写脏 meta）。
 */
final class SessionModelRebinderTest extends TestCase
{
    private AgentKernel $kernel;
    private ProfileRegistry $registry;
    private AgentLoop $loop;
    private InMemorySessionStore $store;

    protected function setUp(): void
    {
        $this->kernel = new AgentKernel(Config::fromArray([
            'AGENT_PROVIDER' => 'fake',
            'AGENT_SESSION_STORE' => 'memory',
        ]));
        $this->kernel->registerProvider('fake', static fn () => new FakeLLMClient(['默认档回复']));
        $this->registry = new ProfileRegistry($this->kernel);
        $this->registry->setProfiles([
            ['id' => 'glm', 'name' => '智谱', 'provider' => 'openai', 'baseUrl' => 'https://x/v4', 'apiKey' => 'k1', 'models' => ['glm-4.6']],
        ], 'glm');
        $this->loop = new AgentLoop(new FakeLLMClient([]), $this->kernel->toolRegistry(), 'sys', 5, []);
        $this->store = new InMemorySessionStore();
    }

    /** resolve 按 pid|model 缓存实例——借实例同一性断言「绑定的是该目录条目的 client」 */
    public function testRebindRestoresSessionChoice(): void
    {
        $msgs = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        $this->store->save('s1', $msgs, ['profileId' => 'glm', 'model' => 'glm-4.6']);
        $expected = $this->registry->resolve('glm', 'glm-4.6');

        $sel = SessionModelRebinder::rebind($this->registry, $this->loop, 's1', $this->store);
        self::assertSame(['profileId' => 'glm', 'model' => 'glm-4.6', 'effort' => null], $sel);
        self::assertSame($expected, $this->loop->client());
    }

    public function testRebindFallsBackWhenProfileDeleted(): void
    {
        $msgs = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        $this->store->save('s1', $msgs, ['profileId' => 'gone', 'model' => 'x']);
        $default = $this->registry->resolve(null, null);

        $sel = SessionModelRebinder::rebind($this->registry, $this->loop, 's1', $this->store);
        self::assertNull($sel, 'profile 已删 → 回落默认档，不记录选择');
        self::assertSame($default, $this->loop->client());
    }

    public function testRebindRecordsActiveClientForCompactFollow(): void
    {
        // compact 插件读 profileRegistry()->activeClient() 作为压缩摘要 client——
        // rebind 绑定 loop 的同时必须记录 activeClient，否则 CLI 侧压缩不跟随当前模型
        $msgs = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        $this->store->save('s1', $msgs, ['profileId' => 'glm', 'model' => 'glm-4.6']);
        $expected = $this->registry->resolve('glm', 'glm-4.6');

        SessionModelRebinder::rebind($this->registry, $this->loop, 's1', $this->store);
        self::assertSame($expected, $this->registry->activeClient());
    }

    public function testRebindReturnsNullWhenNoChoice(): void
    {
        // 会话已存在但从未选择（save 无 selection）
        $msgs = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        $this->store->save('s2', $msgs);
        $default = $this->registry->resolve(null, null);

        $sel = SessionModelRebinder::rebind($this->registry, $this->loop, 's2', $this->store);
        self::assertNull($sel);
        self::assertSame($default, $this->loop->client());

        // /new 场景：会话 id 尚无任何 meta
        $sel2 = SessionModelRebinder::rebind($this->registry, $this->loop, 'brand-new', $this->store);
        self::assertNull($sel2);
        self::assertSame($default, $this->loop->client());
    }

    public function testRebindRestoresEffortOnlySession(): void
    {
        // /effort 而不 /model 的会话（有 effort 无 profile）：resume 后强度不丢
        // client 重绑默认档（无 profile 可解析），selection 返回含 effort 的三键
        $msgs = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        $this->store->save('s3', $msgs, ['profileId' => null, 'model' => null, 'effort' => 'high']);
        $default = $this->registry->resolve(null, null);

        $sel = SessionModelRebinder::rebind($this->registry, $this->loop, 's3', $this->store);
        self::assertSame(['profileId' => null, 'model' => null, 'effort' => 'high'], $sel);
        self::assertSame($default, $this->loop->client());
    }

    public function testRebindCarriesEffortWithProfile(): void
    {
        $msgs = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        $this->store->save('s1', $msgs, ['profileId' => 'glm', 'model' => 'glm-4.6', 'effort' => 'max']);
        $expected = $this->registry->resolve('glm', 'glm-4.6');

        $sel = SessionModelRebinder::rebind($this->registry, $this->loop, 's1', $this->store);
        self::assertSame(['profileId' => 'glm', 'model' => 'glm-4.6', 'effort' => 'max'], $sel);
        self::assertSame($expected, $this->loop->client());
    }
}
