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
}
