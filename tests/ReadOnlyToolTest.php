<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\ReadOnlyTool;
use PhpAgent\Agent\Tool;
use PhpAgent\Agent\ToolRegistry;
use PhpAgent\Hook\ConfirmHook;
use PhpAgent\Hook\HookVetoException;
use PHPUnit\Framework\TestCase;

/**
 * ReadOnlyTool 标记接口：ConfirmHook 借助 ToolRegistry 自动放行声明只读的工具。
 */
final class ReadOnlyToolTest extends TestCase
{
    /** 同时实现 Tool 与 ReadOnlyTool 的最小夹具 */
    private function makeTool(string $name, bool $readOnly): Tool
    {
        return new class($name, $readOnly) implements Tool, ReadOnlyTool {
            public function __construct(private string $n, private bool $ro) {}
            public function name(): string { return $this->n; }
            public function description(): string { return '测试工具'; }
            public function schema(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $input): string { return 'ok'; }
            public function readOnly(): bool { return $this->ro; }
        };
    }

    public function testFindReturnsRegisteredToolOrNull(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe', true));
        self::assertSame('probe', $registry->find('probe')?->name());
        self::assertNull($registry->find('missing'));
    }

    public function testReadOnlyToolBypassesPrompt(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe_ro', true));
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'y';
        }, $registry);

        $hook->handle('before_tool_call', ['tool' => 'probe_ro', 'input' => []]);
        self::assertSame([], $calls, '只读工具不应触发确认');
    }

    public function testNonReadOnlyToolStillPrompts(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe_rw', false));
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'n';
        }, $registry);

        $this->expectException(HookVetoException::class);
        try {
            $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]);
        } finally {
            self::assertSame(['probe_rw'], $calls);
        }
    }

    public function testAlwaysAnswerAllowsSubsequentCalls(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe_rw', false));
        $answers = ['n', 'a'];
        $calls = 0;
        $hook = new ConfirmHook(function () use (&$answers, &$calls): string {
            $calls++;
            return array_shift($answers) ?? 'n';
        }, $registry);

        try {
            $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]);
            self::fail('第一次答 n 应当否决');
        } catch (HookVetoException) {
        }
        $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]); // a → 放行并记住
        $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]); // 已记住，不再问
        self::assertSame(2, $calls, "'a' 之后不应再确认");
    }

    public function testWithoutRegistryKeepsLegacyBehavior(): void
    {
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'y';
        });

        $hook->handle('before_tool_call', ['tool' => 'read_file', 'input' => []]); // 名单内自动放行
        self::assertSame([], $calls);

        $hook->handle('before_tool_call', ['tool' => 'unknown_tool', 'input' => []]); // 名单外仍确认
        self::assertSame(['unknown_tool'], $calls);
    }
}
