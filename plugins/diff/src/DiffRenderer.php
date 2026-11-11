<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Diff;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * diff 渲染：unified 格式 + ANSI 红绿上色（CLI 直接展示；Web 前端剥 ANSI 后按行前缀上色）。
 */
final class DiffRenderer
{
    private const RESET = "\033[0m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const CYAN = "\033[36m";
    private const DIM = "\033[2m";

    /** 旧内容超过此大小时跳过 diff（防止超大文件拖慢/撑爆输出） */
    public const MAX_DIFF_BYTES = 512 * 1024;

    /**
     * @return string 上色后的 unified diff；无差异返回 ''
     */
    public static function render(string $old, string $new, int $maxLines = 80): string
    {
        if ($old === $new) {
            return '';
        }

        $differ = new Differ(new UnifiedDiffOutputBuilder("--- 原内容\n+++ 新内容\n"));
        $text = trim($differ->diff($old, $new));
        if ($text === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $out = [];
        $omitted = 0;
        foreach ($lines as $i => $line) {
            if ($i >= $maxLines) {
                $omitted = count($lines) - $maxLines;
                break;
            }
            $out[] = self::colorize($line);
        }
        $result = implode("\n", $out);
        if ($omitted > 0) {
            $result .= "\n" . self::DIM . "…（diff 共 " . count($lines) . " 行，已截断显示前 {$maxLines} 行）" . self::RESET;
        }
        return $result;
    }

    private static function colorize(string $line): string
    {
        if (str_starts_with($line, '+')) {
            return self::GREEN . $line . self::RESET;
        }
        if (str_starts_with($line, '-')) {
            return self::RED . $line . self::RESET;
        }
        if (str_starts_with($line, '@@')) {
            return self::CYAN . $line . self::RESET;
        }
        return self::DIM . $line . self::RESET;
    }

    /** 供工具返回值判断「这段结果里是否携带 diff 块」（UI 放宽截断用） */
    public static function containsDiff(string $result): bool
    {
        return str_contains($result, "\n+") || str_contains($result, "\n-");
    }
}
