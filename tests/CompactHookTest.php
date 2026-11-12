<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\Plugin\Compact\CompactHook;
use PhpAgent\Plugin\Compact\Compactor;
use PHPUnit\Framework\TestCase;

// 插件类不经 composer autoload（项目插件由 plugin.php require），测试中显式引入
require_once dirname(__DIR__) . '/plugins/compact/src/CompactionResult.php';
require_once dirname(__DIR__) . '/plugins/compact/src/Compactor.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactHook.php';

/**
 * CompactHook 工厂调用语义：惰性（前置守卫不通过不调用）+ 每次压缩事件重新解析（不 memoize）。
 * 修复前 handle 以 ??= 记忆化工厂结果——首次触发后冻结 client/contextTokens，
 * per-turn 切换模型后自动压缩仍用旧值（复审 Important）。
 */
final class CompactHookTest extends TestCase
{
    public function testFactoryInvokedOnEveryCompactionEventNotMemoized(): void
    {
        $calls = 0;
        // 高窗口：即便调用工厂也不会压缩——隔离「工厂调用次数」与「压缩发生」两个语义
        $factory = static function () use (&$calls): Compactor {
            $calls++;
            return new Compactor(new FakeLLMClient([]), 10_000_000, 0.8);
        };
        $hook = new CompactHook($factory);

        // 前置守卫（空历史）直接返回：工厂未被调用
        $hook->handle('before_llm_request', ['messages' => [], 'notes' => [], 'system' => '', 'iteration' => 1]);
        self::assertSame(0, $calls, '前置守卫不通过不调用工厂');

        // 未达阈值：工厂被调用但不压缩（惰性语义 = 第一次真正处理时才构造）
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '任务一']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成']]],
        ];
        $notes = [];
        $hook->handle('before_llm_request', ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1]);
        self::assertSame(1, $calls, '首次处理事件调用工厂');
        self::assertCount(2, $messages, '未达阈值不压缩');
        self::assertSame([], $notes);

        // 第二次事件：工厂再次调用——修复前 ??= 记忆化后恒为 1（client/contextTokens 被冻结）
        $hook->handle('before_llm_request', ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 2]);
        self::assertSame(2, $calls, '每次压缩事件重新解析，不冻结首个 Compactor');
        self::assertCount(2, $messages);
        self::assertSame([], $notes);
    }
}
