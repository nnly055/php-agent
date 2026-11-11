<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Hook\HookPipeline;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Plugin\Compact\CompactPlugin;
use PhpAgent\Plugin\Compact\Compactor;
use PHPUnit\Framework\TestCase;

// 插件类不经 composer autoload（项目插件由 plugin.php require），测试中显式引入
require_once dirname(__DIR__) . '/plugins/compact/src/CompactionResult.php';
require_once dirname(__DIR__) . '/plugins/compact/src/Compactor.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactCommand.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactHook.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactPlugin.php';

/**
 * compact 插件：钩子接线（估算→压缩→notes 写穿）、失败节流、provider 落选逻辑。
 */
final class CompactPluginTest extends TestCase
{
    /** 低阈值 + 高压缩率脚本：让 FakeLLM 的摘要看起来"变小" */
    private function kernelWith(Compactor $compactor, int $contextTokens, float $threshold): AgentKernel
    {
        putenv('AGENT_WORKSPACE=' . sys_get_temp_dir());
        $kernel = new AgentKernel(\PhpAgent\Config::load(sys_get_temp_dir()));
        (new CompactPlugin(
            static fn (): Compactor => $compactor,
            $contextTokens,
            $threshold,
        ))->register($kernel);
        return $kernel;
    }

    private function bigHistory(): array
    {
        $turn = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => str_repeat('早期任务说明。', 50)]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('已完成早期任务。', 50)]]],
        ];
        $history = [];
        for ($i = 0; $i < 4; $i++) {
            array_push($history, ...$turn);
        }
        $history[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '当前任务：继续']]];
        return $history;
    }

    public function testHookCompactsAndWritesThroughNotes(): void
    {
        $fake = new FakeLLMClient([[StreamEvent::text('早期完成了若干任务。'), StreamEvent::end('end_turn')]]);
        $kernel = $this->kernelWith(new Compactor($fake, 128000, 0.0), 200, 0.5); // 阈值 100 tokens 必然触发

        $messages = $this->bigHistory();
        $notes = [];
        $payload = ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1];
        HookPipeline::fire($kernel->hooks(), 'before_llm_request', $payload);

        // messages 写穿：只剩合成对 + 当前任务
        self::assertCount(3, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertStringContainsString('<context-summary>', $messages[0]['content'][0]['text']);
        // notes 写穿：UI 能收到提示
        self::assertCount(1, $notes);
        self::assertStringContainsString('已将前', $notes[0]);
    }

    public function testHookSkipsWhenUnderThreshold(): void
    {
        $fake = new FakeLLMClient([]);
        $kernel = $this->kernelWith(new Compactor($fake, 10_000_000, 0.8), 10_000_000, 0.8);

        $messages = $this->bigHistory();
        $notes = [];
        $payload = ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1];
        HookPipeline::fire($kernel->hooks(), 'before_llm_request', $payload);

        self::assertCount(9, $messages); // 原样
        self::assertSame([], $notes);
    }

    public function testFailureLatchSkipsUntilNextTaskStart(): void
    {
        $broken = new class implements \PhpAgent\LLM\LLMClient {
            public function stream(array $messages, array $tools, string $system): \Generator
            {
                throw new \RuntimeException('无 key');
                yield;
            }
        };
        $kernel = $this->kernelWith(new Compactor($broken, 128000, 0.0), 200, 0.5);

        $messages = $this->bigHistory();
        $notes = [];
        $fire = static function () use (&$messages, &$notes): array {
            $payload = ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1];
            HookPipeline::fire($GLOBALS['kernel']->hooks(), 'before_llm_request', $payload);
            return $notes;
        };
        $GLOBALS['kernel'] = $kernel;

        // 第一次：尝试压缩 → 失败 → note + 进入节流
        $fire();
        self::assertCount(9, $messages);
        self::assertStringContainsString('压缩失败', $notes[0] ?? '');

        // 同任务推进（模拟第 2 轮迭代，尾条变为 tool_result）：节流生效，不再追加 note
        $messages[] = ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'x1', 'name' => 'read_file', 'input' => []]]];
        $messages[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'x1', 'content' => 'data']]];
        $countBefore = count($notes);
        $fire();
        self::assertCount($countBefore, $notes);

        // 新任务起点（尾条是普通 user 文本）：解除节流，再次尝试失败
        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '新任务来了']]];
        $fire();
        self::assertCount($countBefore + 1, $notes);

        unset($GLOBALS['kernel']);
    }

    public function testRegistersUnderNameCompact(): void
    {
        putenv('AGENT_WORKSPACE=' . sys_get_temp_dir());
        $kernel = new AgentKernel(\PhpAgent\Config::load(sys_get_temp_dir()));
        $fake = new FakeLLMClient([]);
        (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))
            ->register($kernel);

        self::assertSame('compact', (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))->name());
        self::assertSame('1.0.0', (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))->version());
        self::assertNotSame([], $kernel->hooks());
        self::assertArrayHasKey('compact', $kernel->commands());
    }
}
