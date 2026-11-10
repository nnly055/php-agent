<?php

declare(strict_types=1);

namespace PhpAgent\LLM;

/**
 * 极简 SSE 解析：把任意切分的网络 chunk 喂进来，吐出完整的 "data:" 载荷行。
 * 不处理跨行 data 拼接（各家 provider 的 JSON 载荷都是单行），够用且健壮。
 */
final class SseStream
{
    private string $buffer = '';

    /** @return string[] */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $out = [];

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = substr($this->buffer, $pos + 1);

            if ($line === '' || str_starts_with($line, ':')) {
                continue; // 事件边界 / 注释
            }
            if (!str_starts_with($line, 'data:')) {
                continue; // event:/id:/retry: 行，载荷的 type 都在 JSON 里，忽略
            }
            $out[] = ltrim(substr($line, 5));
        }

        return $out;
    }
}
