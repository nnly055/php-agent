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
}