<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\LLM\LLMClient;
use PhpAgent\LLM\LLMException;

/**
 * 上下文压缩核心算法：token 估算（保守启发式）、压缩边界选择、
 * 调 LLM 生成摘要并就地替换历史。无 tokenizer，估算宁可偏大（早压缩）。
 *
 * 边界规则：归一化历史中「普通 user 文本消息」= 任务起点（tool_result 消息
 * 虽为 user 角色但被排除）。压缩 [0, splitIndex)，当前任务全程原样保留——
 * 天然不切开 tool_use↔tool_result 配对，满足 Anthropic 交替约束。
 *
 * 替换形态：[user: 摘要] + [assistant: 短确认] + 保留段。
 * 摘要若为单独 user 消息会与保留段开头的 user 连续两条，违反交替约束，
 * 故补一条 assistant 确认恢复交替。
 */
final class Compactor
{
    /** 摘要请求的 system 提示词（与 agent 主循环的 system 无关） */
    public const SUMMARY_SYSTEM = <<<'TXT'
你是对话历史压缩器。把给定的早期对话历史压缩成供后续对话使用的摘要：
1. 保留：任务目标、关键决定与理由、涉及的文件路径与改动内容、未完成事项、用户提出的约束与偏好。
2. 舍弃：中间过程细节、完整工具输出、重复内容。
3. 用中文输出纯文本，按「任务目标 / 关键决定 / 文件改动 / 未完成事项 / 约束偏好」分节。
TXT;

    private const TOKEN_DIVISOR = 2.5; // 字符→token 保守估算（中英混合，宁大勿小）

    /**
     * image 块的固定 token 成本。base64 data 是传输形态，不代表模型的 token 占用，
     * 1600 ≈ 单图实际视觉 token 量级；若不剥离而按 JSON 长度把 base64 计入估算，
     * 一张大图（>~480KB）会让历史永久越过压缩阈值，工具循环每轮都触发多余的总结请求。
     */
    private const IMAGE_TOKEN_COST = 1600;

    public function __construct(
        private readonly LLMClient $client,
        private readonly int $contextTokens = 128000,
        private readonly float $threshold = 0.8,
    ) {
    }

    /**
     * 保守估算：JSON 字符数 + system 长度，除以固定除数向上取整。
     * image 块不计 base64——在副本中整体剔除（原历史绝不被改动），
     * 每块改按 IMAGE_TOKEN_COST 固定计费。
     */
    public static function estimateTokens(array $messages, string $system = ''): int
    {
        // 空历史 + 空 system 直接返回 0：json_encode([]) 会产生 '[]' 两个字符，
        // 向上取整后得 1，与「无内容即无 token」的语义不符（测试已 pin 0）
        if ($messages === [] && $system === '') {
            return 0;
        }
        [$stripped, $imageCount] = self::withoutImageBlocks($messages);
        $chars = mb_strlen(json_encode($stripped, JSON_UNESCAPED_UNICODE) ?: '')
            + mb_strlen($system);
        return (int) ceil($chars / self::TOKEN_DIVISOR) + $imageCount * self::IMAGE_TOKEN_COST;
    }

    /**
     * 深拷贝消息并剔除 image 块，返回 [副本, 图片块数]。只写副本、原历史不动：
     * base64 是传输形态不代表 token 占用，剔除后 JSON 长度与无图历史完全一致，
     * 图片成本统一由 IMAGE_TOKEN_COST 承担。
     *
     * @return array{0: array<int, mixed>, 1: int}
     */
    private static function withoutImageBlocks(array $messages): array
    {
        $imageCount = 0;
        $stripped = [];
        foreach ($messages as $message) {
            $blocks = is_array($message) ? ($message['content'] ?? null) : null;
            if (!is_array($blocks)) {
                $stripped[] = $message;
                continue;
            }
            $kept = [];
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'image') {
                    ++$imageCount;
                    continue;
                }
                $kept[] = $block;
            }
            $copy = $message;
            $copy['content'] = $kept;
            $stripped[] = $copy;
        }
        return [$stripped, $imageCount];
    }

    /** 估算达到「窗口 × 阈值」即触发 */
    public function shouldCompact(array $messages, string $system): bool
    {
        return self::estimateTokens($messages, $system)
            >= (int) round($this->contextTokens * $this->threshold);
    }

    /** 最后一个任务起点下标；0 = 没有可压缩的历史 */
    public static function findSplit(array $messages): int
    {
        $split = 0;
        foreach ($messages as $i => $m) {
            if (is_array($m) && self::isTaskStart($m)) {
                $split = (int) $i;
            }
        }
        return $split;
    }

    /** 普通 user 文本消息 = 任务起点；tool_result 消息被排除 */
    public static function isTaskStart(array $message): bool
    {
        if (($message['role'] ?? '') !== 'user') {
            return false;
        }
        $content = $message['content'] ?? [];
        if (!is_array($content) || $content === []) {
            return false;
        }
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_result') {
                return false;
            }
        }
        return true;
    }

    /**
     * 就地压缩：[0, splitIndex) 换成摘要合成对，其余原样保留。
     * 失败（LLM 异常/空摘要）绝不修改历史，原因写入 note。
     */
    public function compact(array &$messages, string $system): CompactionResult
    {
        $split = self::findSplit($messages);
        if ($split === 0) {
            return new CompactionResult(false, '', 0, 0, 0);
        }
        $before = self::estimateTokens($messages, $system);
        $old = array_slice($messages, 0, $split);

        try {
            $summary = $this->summarize($old);
        } catch (\Throwable $e) {
            return new CompactionResult(
                false,
                '〔上下文压缩〕压缩失败，已保留原历史：' . $e->getMessage(),
                0,
                $before,
                $before,
            );
        }

        $summaryMsg = [
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => "<context-summary>\n{$summary}\n</context-summary>\n\n"
                    . '以上是早期对话的压缩摘要，请基于它继续当前任务。',
            ]],
        ];
        $ackMsg = [
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => '已了解上述摘要，继续处理当前任务。']],
        ];
        $messages = array_merge([$summaryMsg, $ackMsg], array_slice($messages, $split));
        $after = self::estimateTokens($messages, $system);

        return new CompactionResult(
            true,
            sprintf(
                '〔上下文压缩〕已将前 %d 条消息压缩为摘要（估算 %d → %d tokens）',
                $split,
                $before,
                $after,
            ),
            $split,
            $before,
            $after,
        );
    }

    /** 发起无工具总结请求，聚合 text_delta；失败抛异常（compact 捕获） */
    private function summarize(array $old): string
    {
        $request = [[
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => "以下是需要压缩的早期对话历史：\n\n<history>\n"
                    . self::serializeHistory($old)
                    . "\n</history>",
            ]],
        ]];
        $text = '';
        foreach ($this->client->stream($request, [], self::SUMMARY_SYSTEM) as $ev) {
            if ($ev->type === 'text_delta') {
                $text .= $ev->data['text'];
            }
        }
        $summary = trim($text);
        if ($summary === '') {
            throw new LLMException('摘要请求未返回文本');
        }
        return $summary;
    }

    /** 历史序列化为摘要请求文本：文本全量、tool_use 参数全量、tool_result 截断到 2000 字符 */
    private static function serializeHistory(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? '?';
            foreach (($m['content'] ?? []) as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'text') {
                    $lines[] = "[{$role}] " . (string) ($block['text'] ?? '');
                } elseif ($type === 'tool_use') {
                    $json = json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}';
                    $lines[] = "[{$role}] [调用工具 " . ($block['name'] ?? '?') . " 参数 {$json}]";
                } elseif ($type === 'tool_result') {
                    $out = mb_substr((string) ($block['content'] ?? ''), 0, 2000);
                    $lines[] = "[{$role}] [工具结果 {$out}]";
                }
            }
        }
        return implode("\n", $lines);
    }
}
