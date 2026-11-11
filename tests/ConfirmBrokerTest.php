<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\StreamEvent;
use PhpAgent\Server\ConfirmBroker;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

final class ConfirmBrokerTest extends TestCase
{
    public function testResolveYesReturnsDecisionAndEmitsRequest(): void
    {
        $broker = new ConfirmBroker();
        $emitted = [];
        // 注意：emitter 闭包必须显式捕获 $broker，否则内层 futureTick 的 use ($broker) 拿到 null
        $broker->setEmitter(static function (StreamEvent $e) use (&$emitted, $broker): void {
            $emitted[] = $e;
            // 模拟前端立即同意：在事件循环下一拍 resolve
            Loop::futureTick(static function () use ($broker, $e): void {
                $broker->resolve($e->data['id'], 'y');
            });
        });

        $decision = null;
        Loop::futureTick(static function () use ($broker, &$decision): void {
            $decision = $broker->request('run_command', ['command' => 'ls']);
        });
        Loop::run();

        self::assertSame('y', $decision);
        self::assertCount(1, $emitted);
        self::assertSame('confirm_request', $emitted[0]->type);
        self::assertSame('run_command', $emitted[0]->data['tool']);
        self::assertSame('ls', $emitted[0]->data['preview']);
        self::assertNull($broker->pendingId());
    }

    public function testSynchronousResolveInsideEmitterWorks(): void
    {
        // 钉住加固：先注册 pending 再 emit，emitter 内同步 resolve() 也能命中
        $broker = new ConfirmBroker();
        $broker->setEmitter(static function (StreamEvent $e) use ($broker): void {
            $broker->resolve($e->data['id'], 'y');
        });

        $decision = null;
        Loop::futureTick(static function () use ($broker, &$decision): void {
            $decision = $broker->request('run_command', ['command' => 'ls']);
        });
        Loop::run();

        self::assertSame('y', $decision);
        self::assertNull($broker->pendingId());
    }

    public function testEmitterThrowCleansUpAndRethrows(): void
    {
        // 钉住无泄漏：emitter 同步抛错 → 清理 pending/timer/activeId 后原样重抛
        $broker = new ConfirmBroker(0.05);
        $broker->setEmitter(static function (StreamEvent $e): void {
            throw new \RuntimeException('emit-boom');
        });

        $caught = null;
        Loop::futureTick(static function () use ($broker, &$caught): void {
            try {
                $broker->request('run_command', ['command' => 'ls']);
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });
        Loop::run();

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('emit-boom', $caught->getMessage());
        self::assertNull($broker->pendingId());
    }

    public function testResolveUnknownIdReturnsFalse(): void
    {
        $broker = new ConfirmBroker();
        self::assertFalse($broker->resolve('nope', 'y'));
    }

    public function testDiscardActiveResolvesNo(): void
    {
        $broker = new ConfirmBroker();
        $broker->setEmitter(static function (StreamEvent $e) use ($broker): void {
            Loop::futureTick(static function () use ($broker): void {
                $broker->discardActive();
            });
        });
        $decision = null;
        Loop::futureTick(static function () use ($broker, &$decision): void {
            $decision = $broker->request('write_file', ['path' => 'x', 'content' => 'y']);
        });
        Loop::run();
        self::assertSame('n', $decision);
    }

    public function testTimeoutResolvesNo(): void
    {
        // 用可注入超时秒数的构造参数加速测试（生产默认 120）
        $broker = new ConfirmBroker(0.05);
        $broker->setEmitter(static function (StreamEvent $e): void {});
        $decision = null;
        Loop::futureTick(static function () use ($broker, &$decision): void {
            $decision = $broker->request('run_command', ['command' => 'sleep 1']);
        });
        Loop::run();
        self::assertSame('n', $decision);
        self::assertNull($broker->pendingId());
    }

    public function testWriteFilePreviewShowsDiffForExistingFile(): void
    {
        $ws = sys_get_temp_dir() . '/php-agent-broker-' . uniqid();
        mkdir($ws, 0777, true);
        file_put_contents($ws . '/a.txt', "line1\nline2\n");
        $broker = new ConfirmBroker(120.0, $ws);
        $preview = null;
        $broker->setEmitter(static function (StreamEvent $e) use ($broker, &$preview): void {
            $preview = $e->data['preview'];
            Loop::futureTick(static fn () => $broker->resolve($e->data['id'], 'n'));
        });
        Loop::futureTick(static fn () => $broker->request('write_file', ['path' => 'a.txt', 'content' => "line1\nline2-changed\n"]));
        Loop::run();
        self::assertStringContainsString('-line2', $preview);
        self::assertStringContainsString('+line2-changed', $preview);
        @unlink($ws . '/a.txt');
        @rmdir($ws);
    }

    public function testConfirmHookIntegrationDeniesThenAllows(): void
    {
        $ws = sys_get_temp_dir() . '/php-agent-broker-int-' . uniqid();
        mkdir($ws, 0777, true);
        $registry = (new \PhpAgent\Agent\ToolRegistry())->register(new \PhpAgent\Tools\WriteFileTool($ws));
        $broker = new ConfirmBroker(120.0, $ws);

        // 第一次拒绝，第二次同意
        $answers = ['n', 'y'];
        $i = 0;
        $broker->setEmitter(static function (StreamEvent $e) use ($broker, &$answers, &$i): void {
            $a = $answers[$i++] ?? 'n';
            Loop::futureTick(static fn () => $broker->resolve($e->data['id'], $a));
        });
        $hook = new \PhpAgent\Hook\ConfirmHook(static fn (string $t, array $in): string => $broker->request($t, $in), $registry);

        $script = [
            [
                \PhpAgent\LLM\StreamEvent::toolStart('t1', 'write_file'),
                \PhpAgent\LLM\StreamEvent::toolEnd('t1', 'write_file', ['path' => 'x.txt', 'content' => 'hi']),
                \PhpAgent\LLM\StreamEvent::end('tool_use'),
            ],
            [
                \PhpAgent\LLM\StreamEvent::toolStart('t2', 'write_file'),
                \PhpAgent\LLM\StreamEvent::toolEnd('t2', 'write_file', ['path' => 'x.txt', 'content' => 'hi']),
                \PhpAgent\LLM\StreamEvent::end('tool_use'),
            ],
            [\PhpAgent\LLM\StreamEvent::text('done'), \PhpAgent\LLM\StreamEvent::end('end_turn')],
        ];
        $loop = new \PhpAgent\Agent\AgentLoop(new \PhpAgent\LLM\FakeLLMClient($script), $registry, 'sys', 10, [$hook]);

        $messages = [];
        $final = null;
        Loop::futureTick(static function () use ($loop, &$messages, &$final): void {
            $final = $loop->run($messages, '写文件');
        });
        Loop::run();

        self::assertSame('done', $final);
        self::assertStringStartsWith('DENIED:', $messages[2]['content'][0]['content']);
        self::assertFileExists($ws . '/x.txt');
        @unlink($ws . '/x.txt');
        @rmdir($ws);
    }
}