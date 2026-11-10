<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\SseStream;
use PHPUnit\Framework\TestCase;

final class SseStreamTest extends TestCase
{
    public function testSplitsCompleteChunks(): void
    {
        $sse = new SseStream();
        $out = $sse->feed("data: {\"a\":1}\n\ndata: [DONE]\n\n");
        self::assertSame(['{"a":1}', '[DONE]'], $out);
    }

    public function testHandlesPartialLinesAcrossFeeds(): void
    {
        $sse = new SseStream();
        self::assertSame([], $sse->feed('data: {"long'));                       // 无换行 → 整行不完整
        self::assertSame(['{"longjson":true}'], $sse->feed('json":true}' . "\n\n")); // 补齐后吐出
        self::assertSame([], $sse->feed('data: eve'));
        self::assertSame(['event:done'], $sse->feed("nt:done\n"));              // 跨 chunk 拼接
    }

    public function testIgnoresEventAndCommentLines(): void
    {
        $sse = new SseStream();
        $out = $sse->feed(": keepalive\n event:x\nevent: message\ndata: payload1\n\n");
        // 注意 ' event:x'（带前导空格）不是合法 event 行，但也不以 data: 开头 → 忽略
        self::assertSame(['payload1'], $out);
    }
}
