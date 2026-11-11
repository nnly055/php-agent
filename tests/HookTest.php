<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Agent\ToolRegistry;
use PhpAgent\Hook\AuditLogHook;
use PhpAgent\Hook\ConfirmHook;
use PhpAgent\Hook\HookVetoException;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\LLMClient;
use PhpAgent\LLM\LLMException;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Tools\WriteFileTool;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 钩子系统：否决、审计、上下文注入、after_turn、授权状态机。
 */
final class HookTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/php-agent-hook-' . uniqid();
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace . '/hello.php');
        @rmdir($this->workspace);
    }

    public function testVetoDeniesToolExecution(): void
    {
        $registry = (new ToolRegistry())->register(new WriteFileTool($this->workspace));
        $veto = new class implements \PhpAgent\Hook\AgentHook {
            public function events(): array
            {
                return ['before_tool_call'];
            }

            public function handle(string $event, array $payload): void
            {
                throw new HookVetoException('测试否决');
            }
        };

        $script = [
            [
                StreamEvent::toolStart('t1', 'write_file'),
                StreamEvent::toolEnd('t1', 'write_file', ['path' => 'hello.php', 'content' => 'x']),
                StreamEvent::end('tool_use'),
            ],
            [StreamEvent::text('收到拒绝结果，任务结束。'), StreamEvent::end('end_turn')],
        ];
        $loop = new AgentLoop(new FakeLLMClient($script), $registry, 'sys', 10, [$veto]);
        $messages = [];
        $final = $loop->run($messages, '创建文件');

        // 文件没有被创建，但循环正常走完，模型收到了 DENIED 结果
        self::assertFileDoesNotExist($this->workspace . '/hello.php');
        self::assertSame('收到拒绝结果，任务结束。', $final);
        $toolResult = $messages[2]['content'][0]['content'];
        self::assertStringStartsWith('DENIED:', $toolResult);
        self::assertStringContainsString('测试否决', $toolResult);
    }

    public function testAuditHookWritesJsonl(): void
    {
        $auditFile = $this->workspace . '/audit.jsonl';
        $audit = new AuditLogHook($auditFile);

        $registry = (new ToolRegistry())->register(new WriteFileTool($this->workspace));
        $script = [
            [
                StreamEvent::toolStart('t1', 'write_file'),
                StreamEvent::toolEnd('t1', 'write_file', ['path' => 'hello.php', 'content' => 'abc']),
                StreamEvent::end('tool_use'),
            ],
            [StreamEvent::text('done'), StreamEvent::end('end_turn')],
        ];
        $loop = new AgentLoop(new FakeLLMClient($script), $registry, 'sys', 10, [$audit]);
        $messages = [];
        $loop->run($messages, '写文件');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($auditFile))));
        self::assertCount(2, $lines);

        $before = json_decode($lines[0], true);
        $after = json_decode($lines[1], true);
        self::assertSame('before_tool_call', $before['event']);
        self::assertSame('write_file', $before['tool']);
        self::assertSame('hello.php', $before['input']['path']);
        self::assertSame('after_tool_result', $after['event']);
        self::assertStringContainsString('OK: 已写入', $after['result']);
        self::assertNotFalse(strtotime($before['ts']));
    }

    public function testBeforeLlmRequestCanModifyMessages(): void
    {
        $captured = null;
        $captureClient = new class () implements LLMClient {
            public ?array $lastMessages = null;

            public function stream(array $messages, array $tools, string $system): \Generator
            {
                $this->lastMessages = $messages;
                yield StreamEvent::text('ok');
                yield StreamEvent::end('end_turn');
            }
        };

        $injector = new class implements \PhpAgent\Hook\AgentHook {
            public function events(): array
            {
                return ['before_llm_request'];
            }

            public function handle(string $event, array $payload): void
            {
                // 上下文注入：改写首条用户消息（引用传播）
                $payload['messages'][0]['content'][0]['text'] .= ' [注入的上下文]';
            }
        };

        $loop = new AgentLoop($captureClient, new ToolRegistry(), 'sys', 10, [$injector]);
        $messages = [];
        $loop->run($messages, '原始问题');

        self::assertNotNull($captureClient->lastMessages);
        self::assertStringEndsWith('[注入的上下文]', $captureClient->lastMessages[0]['content'][0]['text']);
    }

    public function testAfterTurnFiresWithPayload(): void
    {
        $fired = [];
        $hook = new class ($fired) implements \PhpAgent\Hook\AgentHook {
            public array $payload = [];

            public function __construct(&$fired)
            {
                $this->payload = &$fired;
            }

            public function events(): array
            {
                return ['after_turn'];
            }

            public function handle(string $event, array $payload): void
            {
                $this->payload = $payload;
            }
        };

        $script = [[StreamEvent::text('最终回答'), StreamEvent::end('end_turn')]];
        $loop = new AgentLoop(new FakeLLMClient($script), new ToolRegistry(), 'sys', 10, [$hook]);
        $messages = [];
        $loop->run($messages, 'hi');

        self::assertIsArray($hook->payload);
        self::assertSame('最终回答', $hook->payload['text']);
        self::assertCount(2, $hook->payload['messages']);
        self::assertSame(1, $hook->payload['iterations']);
    }

    public function testConfirmHookStateMachine(): void
    {
        $prompts = 0;
        $prompt = static function () use (&$prompts): string {
            $prompts++;
            return 'a';
        };

        $hook = new ConfirmHook($prompt);

        // 只读工具：不询问，直接放行
        $hook->handle('before_tool_call', ['tool' => 'read_file', 'input' => []]);
        self::assertSame(0, $prompts);

        // 需授权工具：询问一次，'a' 后加入会话白名单
        $hook->handle('before_tool_call', ['tool' => 'run_command', 'input' => ['command' => 'ls']]);
        self::assertSame(1, $prompts);

        // 白名单生效：不再询问
        $hook->handle('before_tool_call', ['tool' => 'run_command', 'input' => ['command' => 'pwd']]);
        self::assertSame(1, $prompts);

        // 第三方工具默认需授权
        $hook->handle('before_tool_call', ['tool' => 'some_plugin_tool', 'input' => []]);
        self::assertSame(2, $prompts);
    }

    public function testConfirmHookDenyThrowsVeto(): void
    {
        $hook = new ConfirmHook(static fn (): string => 'n');
        $this->expectException(HookVetoException::class);
        $hook->handle('before_tool_call', ['tool' => 'write_file', 'input' => []]);
    }

    public function testBeforeLlmRequestVetoAbortsRun(): void
    {
        $veto = new class implements \PhpAgent\Hook\AgentHook {
            public function events(): array
            {
                return ['before_llm_request'];
            }

            public function handle(string $event, array $payload): void
            {
                throw new HookVetoException('策略禁止');
            }
        };
        $loop = new AgentLoop(new FakeLLMClient([]), new ToolRegistry(), 'sys', 10, [$veto]);
        $messages = [];

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('被钩子否决');
        $loop->run($messages, 'hi');
    }
}
