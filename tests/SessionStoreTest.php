<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Session\Store\InMemorySessionStore;
use PhpAgent\Session\Store\JsonFileSessionStore;
use PhpAgent\Session\Store\RedisSessionStore;
use PhpAgent\Session\SessionMeta;
use PhpAgent\Session\SessionStore;
use PhpAgent\Session\Store\Sqlite3SessionStore;
use PHPUnit\Framework\TestCase;

/**
 * 四种 SessionStore 实现的统一契约测试（redis 本地无服务则跳过）。
 */
final class SessionStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-agent-sess-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return SessionStore[] */
    private function stores(): array
    {
        $stores = [
            'memory' => new InMemorySessionStore(),
            'json' => new JsonFileSessionStore($this->dir),
        ];
        if (class_exists(\SQLite3::class)) {
            $stores['sqlite3'] = new Sqlite3SessionStore($this->dir);
        }
        return $stores;
    }

    public function testRoundtripListAndDelete(): void
    {
        foreach ($this->stores() as $kind => $store) {
            $messages = [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => "测试{$kind}：这是一条足够长的标题用来验证派生标题的截断行为是否完全正常运作无误"]]],
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '回复A']]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => '第二条']]],
            ];

            $store->save("t{$kind}", $messages);
            $store->save("u{$kind}", [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => "另一个会话{$kind}"]]],
            ]);

            // load 往返完全一致
            self::assertSame($messages, $store->load("t{$kind}"), "store={$kind}");

            // list 与 delete
            self::assertCount(2, $store->list(), "store={$kind}");
            $metas = $store->list();
            $meta = null;
            foreach ($metas as $m) {
                if ($m->id === "t{$kind}") {
                    $meta = $m;
                }
            }
            self::assertNotNull($meta, "store={$kind}");
            self::assertSame(3, $meta->messageCount);
            self::assertStringContainsString("测试{$kind}", $meta->title);
            self::assertLessThanOrEqual(41, mb_strlen($meta->title));

            $store->delete("u{$kind}");
            self::assertCount(1, $store->list(), "store={$kind}");
            self::assertSame([], $store->load("u{$kind}"), "store={$kind}");
        }
    }

    public function testSavePreservesTitleAndCreatedAt(): void
    {
        $store = new InMemorySessionStore();
        $store->save('s', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '第一句作为标题']]],
        ]);
        $title = $store->list()[0]->title;
        $createdAt = $store->list()[0]->createdAt;

        usleep(1100000); // 保证 updatedAt 变化
        $store->save('s', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '第一句作为标题']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ok']]],
        ]);

        $meta = $store->list()[0];
        self::assertSame($title, $meta->title, '标题一旦派生应固定');
        self::assertSame($createdAt, $meta->createdAt);
        self::assertSame(2, $meta->messageCount);
    }

    public function testLoadMissingReturnsEmpty(): void
    {
        foreach ($this->stores() as $kind => $store) {
            self::assertSame([], $store->load('nope-' . $kind), "store={$kind}");
        }
    }

    public function testJsonStoreIsAtomicAndReadable(): void
    {
        $store = new JsonFileSessionStore($this->dir);
        $store->save('a', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '你好（中文不转义）']]],
        ]);

        $files = glob($this->dir . '/*.json') ?: [];
        self::assertCount(1, $files);
        self::assertSame([], glob($this->dir . '/*.tmp.*') ?: [], '不应残留临时文件');

        $raw = file_get_contents($files[0]);
        self::assertStringContainsString('你好（中文不转义）', (string) $raw);
    }

    /**
     * 选择持久化契约：对四个实现各跑一遍共享断言
     * （redis 本地无服务则跳过，与 testRedisStoreIfAvailable 同一策略）。
     */
    public function testSelectionPersistence(): void
    {
        foreach ($this->stores() as $kind => $store) {
            $this->assertSelectionPersistence($store);
        }
    }

    public function testRedisSelectionPersistence(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('本机未装 ext-redis');
        }
        try {
            $store = new RedisSessionStore('127.0.0.1', 6379);
            $store->list(); // 探活
        } catch (\Throwable $e) {
            self::markTestSkipped('本地 Redis 不可用: ' . $e->getMessage());
        }

        $store->delete('sel-1'); // 清理可能的历史残留，保证初始态干净
        $this->assertSelectionPersistence($store);
        $store->delete('sel-1');
    }

    /** 选择持久化：save 传 selection 落盘，null 保留旧值，缺省新会话为 null（继承默认档） */
    private function assertSelectionPersistence(SessionStore $store): void
    {
        $store->save('sel-1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]]);
        self::assertNull($store->list(10)[0]->profileId ?? null, '初始无选择');
        // 用 list 找到 sel-1（list 按 updatedAt 排序，多会话时不稳定）——按 id 过滤
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'sel-1'))[0];
        self::assertNull($meta->profileId);

        $store->save('sel-1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], ['profileId' => 'p-1', 'model' => 'glm-4.7']);
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'sel-1'))[0];
        self::assertSame('p-1', $meta->profileId);
        self::assertSame('glm-4.7', $meta->model);

        $store->save('sel-1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]]); // null = 保留
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'sel-1'))[0];
        self::assertSame('p-1', $meta->profileId, 'null selection 保留旧值');
    }

    public function testEffortPersistence(): void
    {
        foreach ($this->stores() as $kind => $store) {
            $this->assertEffortPersistence($store);
            $store->delete('eff-1');
        }
    }

    /** effort 持久化：三键 selection 落盘；显式 null 是权威覆盖（不是缺失）——array_key_exists 语义 */
    private function assertEffortPersistence(SessionStore $store): void
    {
        $msg = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]];
        $store->save('eff-1', $msg, ['profileId' => 'p', 'model' => 'm', 'effort' => 'high']);
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'eff-1'))[0];
        self::assertSame('high', $meta->effort);
        self::assertSame('p', $meta->profileId);

        $store->save('eff-1', $msg); // null selection = 保留旧值
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'eff-1'))[0];
        self::assertSame('high', $meta->effort, 'null selection 保留 effort');

        $store->save('eff-1', $msg, ['profileId' => null, 'model' => 'm', 'effort' => null]); // 显式 null = 权威覆盖
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'eff-1'))[0];
        self::assertNull($meta->effort, '显式 null 覆盖旧 effort（?? 会误判缺失）');
        self::assertNull($meta->profileId, '显式 null 覆盖旧 profileId');
    }

    public function testMetaFromArrayLegacyWithoutEffort(): void
    {
        // 旧数据（本功能上线前写入）缺 effort 键 → null，不报错
        $m = SessionMeta::fromArray(['id' => 'x', 'title' => 't', 'createdAt' => 1, 'updatedAt' => 2, 'messageCount' => 0]);
        self::assertNull($m->effort);
    }

    public function testRedisStoreIfAvailable(): void
    {
        if (!class_exists(\Redis::class)) {
            self::markTestSkipped('本机未装 ext-redis');
        }
        try {
            $store = new RedisSessionStore('127.0.0.1', 6379);
            $store->list(); // 探活
        } catch (\Throwable $e) {
            self::markTestSkipped('本地 Redis 不可用: ' . $e->getMessage());
        }

        $id = 'redis-test-' . uniqid();
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'redis 会话测试']]],
        ];
        $store->save($id, $messages);
        self::assertSame($messages, $store->load($id));

        $ids = array_map(static fn ($m) => $m->id, $store->list(50));
        self::assertContains($id, $ids);

        $store->delete($id);
        self::assertSame([], $store->load($id));
    }

    public function testDeriveTitleFallsBackToImagePlaceholder(): void
    {
        $img = ['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/png', 'data' => 'AAAA']]];
        self::assertSame('[图片]', SessionMeta::deriveTitle([$img]));
        // 图文混合：文本优先
        $both = ['role' => 'user', 'content' => [['type' => 'image', 'media_type' => 'image/png', 'data' => 'AAAA'], ['type' => 'text', 'text' => '看这里']]];
        self::assertSame('看这里', SessionMeta::deriveTitle([$both]));
    }
}
