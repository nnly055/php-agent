<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Agent\ToolRegistry;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Tools\ReadFileTool;
use PhpAgent\Tools\WriteFileTool;
use PHPUnit\Framework\TestCase;

/**
 * 离线验证 agent 循环：FakeLLM 触发 write_file 工具 → 结果回填 → 第二轮给最终回答。
 */
final class AgentLoopTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/php-agent-test-' . uniqid();
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace . '/hello.php');
        @rmdir($this->workspace);
    }

    public function testLoopExecutesToolAndReturnsFinalText(): void
    {
        $registry = (new ToolRegistry())
            ->register(new WriteFileTool($this->workspace))
            ->register(new ReadFileTool($this->workspace));

        $script = [
            [
                StreamEvent::text('好的，我来创建文件。'),
                StreamEvent::toolStart('t1', 'write_file'),
                StreamEvent::toolInputDelta('t1', '{"path":"hel'),
                StreamEvent::toolInputDelta('t1', 'lo.php"}'),
                StreamEvent::toolEnd('t1', 'write_file', [
                    'path' => 'hello.php',
                    'content' => "<?php\necho 'hi';\n",
                ]),
                StreamEvent::end('tool_use'),
            ],
            [
                StreamEvent::text('完成，文件已创建。'),
                StreamEvent::end('end_turn'),
            ],
        ];

        $fake = new FakeLLMClient($script);
        $loop = new AgentLoop($fake, $registry, 'test-system', 10);

        $messages = [];
        $events = [];
        $final = $loop->run($messages, '创建 hello.php', static function (StreamEvent $e) use (&$events): void {
            $events[] = $e->type;
        });

        // 1. 工具真的执行了
        self::assertFileExists($this->workspace . '/hello.php');
        self::assertSame("<?php\necho 'hi';\n", file_get_contents($this->workspace . '/hello.php'));

        // 2. 返回了最终文本
        self::assertSame('完成，文件已创建。', $final);

        // 3. 消息历史结构完整：user → assistant(tool_use) → user(tool_result) → assistant(text)
        self::assertCount(4, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('tool_use', $messages[1]['content'][1]['type']);
        self::assertSame('t1', $messages[1]['content'][1]['id']);
        self::assertSame('user', $messages[2]['role']);
        self::assertSame('tool_result', $messages[2]['content'][0]['type']);
        self::assertSame('t1', $messages[2]['content'][0]['tool_use_id']);
        self::assertStringContainsString('OK: 已写入', $messages[2]['content'][0]['content']);
        self::assertSame('assistant', $messages[3]['role']);

        // 4. 事件流包含工具事件
        self::assertContains('tool_use_start', $events);
        self::assertContains('tool_result', $events);
        self::assertContains('message_end', $events);
    }

    public function testMaxIterationsGuard(): void
    {
        $registry = new ToolRegistry();

        // 无限要求调用不存在工具 → 应在 maxIterations 处熔断
        $turns = [];
        for ($i = 0; $i < 5; $i++) {
            $turns[] = [
                StreamEvent::toolStart("t$i", 'nope'),
                StreamEvent::toolEnd("t$i", 'nope', []),
                StreamEvent::end('tool_use'),
            ];
        }
        $fake = new FakeLLMClient($turns);
        $loop = new AgentLoop($fake, $registry, 'sys', 3);

        $messages = [];
        $this->expectException(\PhpAgent\LLM\LLMException::class);
        $loop->run($messages, 'test');
    }

    public function testErrorEventThrows(): void
    {
        $fake = new FakeLLMClient([
            [StreamEvent::error('boom')],
        ]);
        $loop = new AgentLoop($fake, new ToolRegistry(), 'sys', 3);

        $messages = [];
        $this->expectException(\PhpAgent\LLM\LLMException::class);
        $this->expectExceptionMessage('boom');
        $loop->run($messages, 'test');
    }

    public function testHookNotesAreEmittedAsNoticeEvents(): void
    {
        // 钩子向 payload['notes']（引用槽）追加文本 → 循环应产出 notice 事件
        $hook = new class implements \PhpAgent\Hook\AgentHook {
            public function events(): array
            {
                return ['before_llm_request'];
            }

            public function handle(string $event, array $payload): void
            {
                $payload['notes'][] = '测试通知';
            }
        };

        $fake = new FakeLLMClient([[StreamEvent::text('ok'), StreamEvent::end('end_turn')]]);
        $loop = new AgentLoop($fake, new ToolRegistry(), 'test-system', 5, [$hook]);

        $messages = [];
        $events = [];
        $loop->run($messages, 'hi', static function (StreamEvent $e) use (&$events): void {
            $events[] = $e;
        });

        $notices = array_values(array_filter(
            $events,
            static fn (StreamEvent $e) => $e->type === 'notice'
        ));
        self::assertCount(1, $notices);
        self::assertSame('测试通知', $notices[0]->data['text']);
    }

    public function testRunBuildsImageBlocksBeforeText(): void
    {
        $loop = new AgentLoop(new FakeLLMClient([[StreamEvent::text('ok'), StreamEvent::end('end_turn')]]), new ToolRegistry(), 'sys', 5);
        $messages = [];
        $final = $loop->run($messages, '这是什么', null, [['media_type' => 'image/png', 'data' => base64_encode('abc')]]);
        self::assertSame('ok', $final);
        $content = $messages[0]['content'];
        self::assertSame('image', $content[0]['type']);
        self::assertSame('image/png', $content[0]['media_type']);
        self::assertSame(base64_encode('abc'), $content[0]['data']);
        self::assertSame(['type' => 'text', 'text' => '这是什么'], $content[1]);
    }

    public function testRunPureImageAllowsEmptyText(): void
    {
        // 有图 + 空文本 → 只含 image 块
        $messages = [];
        (new AgentLoop(new FakeLLMClient([[StreamEvent::text('ok'), StreamEvent::end('end_turn')]]), new ToolRegistry(), 'sys', 5))
            ->run($messages, '', null, [['media_type' => 'image/jpeg', 'data' => 'AAAA']]);
        self::assertCount(1, $messages[0]['content']);
        self::assertSame('image', $messages[0]['content'][0]['type']);
    }

    public function testRunRejectsInvalidImages(): void
    {
        $loop = new AgentLoop(new FakeLLMClient([]), new ToolRegistry(), 'sys', 5);
        $messages = [];
        $this->expectException(\InvalidArgumentException::class);
        $loop->run($messages, 'hi', null, [['media_type' => 'image/svg+xml', 'data' => 'AAAA']]); // 类型不在白名单
    }

    public function testNormalizeImagesRejectsMoreThanFive(): void
    {
        $images = array_fill(0, 6, ['media_type' => 'image/png', 'data' => base64_encode('x')]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('最多 5 张');
        AgentLoop::normalizeImages($images);
    }

    public function testNormalizeImagesRejectsOversizeImage(): void
    {
        // 解码后超过 5MB → 抛异常
        $images = [['media_type' => 'image/png', 'data' => base64_encode(str_repeat('A', 5 * 1024 * 1024 + 1))]];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('5MB');
        AgentLoop::normalizeImages($images);
    }

    public function testNormalizeImagesRejectsNonBase64(): void
    {
        $images = [['media_type' => 'image/png', 'data' => '!!']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base64');
        AgentLoop::normalizeImages($images);
    }

    public function testNormalizeImagesIdempotentForBlockForm(): void
    {
        // 输入已是块形态 → 幂等：原样归一化输出，且对输出再归一化结果不变
        $block = ['type' => 'image', 'media_type' => 'image/png', 'data' => base64_encode('abc')];
        $out = AgentLoop::normalizeImages([$block]);
        self::assertSame([$block], $out);
        self::assertSame($out, AgentLoop::normalizeImages($out));
    }

    public function testNormalizeImagesLowersMediaType(): void
    {
        // media_type 比较前统一 lower，输出归一化为小写
        $out = AgentLoop::normalizeImages([['media_type' => 'IMAGE/PNG', 'data' => base64_encode('abc')]]);
        self::assertSame('image/png', $out[0]['media_type']);
    }

    public function testNormalizeImagesAllowsExactly5MB(): void
    {
        // 边界：解码后恰好 5MB → 允许
        $data = base64_encode(str_repeat('A', 5 * 1024 * 1024));
        $out = AgentLoop::normalizeImages([['media_type' => 'image/webp', 'data' => $data]]);
        self::assertCount(1, $out);
        self::assertSame('image/webp', $out[0]['media_type']);
    }
}
