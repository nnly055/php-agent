<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Plugin\Compact\CompactionResult;
use PhpAgent\Plugin\Compact\Compactor;
use PHPUnit\Framework\TestCase;

// 插件类不经 composer autoload（项目插件由 plugin.php require），测试中显式引入
require_once dirname(__DIR__) . '/plugins/compact/src/CompactionResult.php';
require_once dirname(__DIR__) . '/plugins/compact/src/Compactor.php';

/**
 * Compactor 核心算法：token 估算、压缩边界、摘要替换、失败语义。
 * 全部离线：摘要请求走 FakeLLMClient / 抛异常的桩客户端。
 */
final class CompactorTest extends TestCase
{
    /** 多任务历史：任务一(2条) + 任务二含工具配对(4条)，findSplit 应= 2 */
    private function taskHistory(): array
    {
        return [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '任务一：创建 a.php']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '好的，已创建 a.php']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '任务二：创建 b.php 并运行']]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'write_file', 'input' => ['path' => 'b.php']],
            ]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'OK']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成，b.php 已创建并运行']]],
        ];
    }

    public function testEstimateTokensMatchesFormula(): void
    {
        // json: [{"role":"user","content":[{"type":"text","text":"abcd"}]}] = 59 字符 → ceil(59/2.5) = 24
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'abcd']]]];
        self::assertSame(24, Compactor::estimateTokens($messages));
        self::assertSame(0, Compactor::estimateTokens([], ''));
    }

    public function testEstimateTokensGrowsWithHistory(): void
    {
        $short = $this->taskHistory();
        $long = array_merge($short, $short);
        self::assertGreaterThan(
            Compactor::estimateTokens($short, 'sys'),
            Compactor::estimateTokens($long, 'sys')
        );
    }

    public function testFindSplitAtLastTaskStart(): void
    {
        self::assertSame(2, Compactor::findSplit($this->taskHistory()));
    }

    public function testFindSplitZeroForSingleTask(): void
    {
        $one = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '唯一任务']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成']]],
        ];
        self::assertSame(0, Compactor::findSplit($one));
        self::assertSame(0, Compactor::findSplit([]));
    }

    public function testIsTaskStartExcludesToolResultMessages(): void
    {
        $toolResultMsg = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'OK']]];
        self::assertFalse(Compactor::isTaskStart($toolResultMsg));
        self::assertTrue(Compactor::isTaskStart($this->taskHistory()[2]));
    }

    public function testShouldCompactRespectsThreshold(): void
    {
        $history = $this->taskHistory();
        $tokens = Compactor::estimateTokens($history, 'sys');

        $hot = new Compactor(new FakeLLMClient([]), $tokens, 0.8);      // 阈值=估算值本身 → 触发
        $cold = new Compactor(new FakeLLMClient([]), $tokens * 10, 0.8); // 窗口富余 → 不触发
        self::assertTrue($hot->shouldCompact($history, 'sys'));
        self::assertFalse($cold->shouldCompact($history, 'sys'));
    }

    public function testCompactReplacesWithSummaryPairAndKeepsCurrentTask(): void
    {
        $fake = new FakeLLMClient([
            [StreamEvent::text('- 任务一：创建 a.php（已完成）'), StreamEvent::end('end_turn')],
        ]);
        $compactor = new Compactor($fake, 128000, 0.8);

        $messages = $this->taskHistory();
        $result = $compactor->compact($messages, 'sys');

        // 结果形态：合成对(2条) + 保留当前任务(4条) = 6 条
        self::assertTrue($result->compacted);
        self::assertSame(2, $result->removedMessages);
        self::assertCount(6, $messages);

        // 摘要 user 消息 + assistant 确认，恢复 user/assistant 交替
        self::assertSame('user', $messages[0]['role']);
        self::assertStringContainsString('<context-summary>', $messages[0]['content'][0]['text']);
        self::assertStringContainsString('任务一', $messages[0]['content'][0]['text']);
        self::assertSame('assistant', $messages[1]['role']);

        // 保留段原样：任务二起点 + tool_use/tool_result 配对完整
        self::assertSame('任务二：创建 b.php 并运行', $messages[2]['content'][0]['text']);
        self::assertSame('tool_use', $messages[3]['content'][0]['type']);
        self::assertSame('t1', $messages[4]['content'][0]['tool_use_id']);

        // 提示文本可读
        self::assertStringContainsString('已将前 2 条消息压缩为摘要', $result->note);
    }

    public function testCompactNoopWhenSingleTask(): void
    {
        $compactor = new Compactor(new FakeLLMClient([]), 128000, 0.8);
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '唯一任务']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成']]],
        ];
        $result = $compactor->compact($messages, '');
        self::assertFalse($result->compacted);
        self::assertSame('', $result->note);
        self::assertCount(2, $messages); // 原历史不动
    }

    public function testCompactFailureKeepsHistoryIntact(): void
    {
        $boom = new class implements \PhpAgent\LLM\LLMClient {
            public function stream(array $messages, array $tools, string $system): \Generator
            {
                throw new \RuntimeException('网络断了');
                yield; // 生成器语法占位
            }
        };
        $compactor = new Compactor($boom, 128000, 0.8);

        $messages = $this->taskHistory();
        $snapshot = $messages;
        $result = $compactor->compact($messages, '');

        self::assertFalse($result->compacted);
        self::assertStringContainsString('压缩失败', $result->note);
        self::assertStringContainsString('网络断了', $result->note);
        self::assertSame($snapshot, $messages); // 历史原样保留
    }

    public function testCompactEmptySummaryIsFailure(): void
    {
        // FakeLLM 脚本播完后的兜底文本会返回内容，这里用只 yield 非文本事件的客户端
        $silent = new class implements \PhpAgent\LLM\LLMClient {
            public function stream(array $messages, array $tools, string $system): \Generator
            {
                yield StreamEvent::end('end_turn');
            }
        };
        $compactor = new Compactor($silent, 128000, 0.8);
        $messages = $this->taskHistory();
        $result = $compactor->compact($messages, '');
        self::assertFalse($result->compacted);
        self::assertStringContainsString('压缩失败', $result->note);
        self::assertCount(6, $messages);
    }
}
