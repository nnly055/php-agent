<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Session\Store\InMemorySessionStore;
use PhpAgent\Session\Store\JsonFileSessionStore;
use PhpAgent\Session\Store\RedisSessionStore;
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
}
