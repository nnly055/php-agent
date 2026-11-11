<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\StreamEvent;
use PHPUnit\Framework\TestCase;

final class StreamEventTest extends TestCase
{
    public function testConfirmRequestShape(): void
    {
        $e = StreamEvent::confirmRequest('cf_1', 'write_file', ['path' => 'a.php'], 'diff-text');
        self::assertSame('confirm_request', $e->type);
        self::assertSame('cf_1', $e->data['id']);
        self::assertSame('write_file', $e->data['tool']);
        self::assertSame(['path' => 'a.php'], $e->data['input']);
        self::assertSame('diff-text', $e->data['preview']);
    }
}