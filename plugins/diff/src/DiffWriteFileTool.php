<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Diff;

use PhpAgent\Agent\Tool;
use PhpAgent\Tools\WriteFileTool;

/**
 * write_file 装饰器（组合模式，不依赖内置类可继承）：
 * 写前读旧内容、写后把 unified diff 附加进返回文本。
 * 通过 registerTool 同名覆盖内置 write_file（模型调用方式完全不变）。
 */
final class DiffWriteFileTool implements Tool
{
    private const MAX_DIFF_BYTES = DiffRenderer::MAX_DIFF_BYTES;

    public function __construct(
        private readonly string $workspace,
        private readonly WriteFileTool $inner,
    ) {
    }

    public static function wrap(string $workspace): self
    {
        return new self($workspace, new WriteFileTool($workspace));
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function description(): string
    {
        return $this->inner->description() . ' 覆盖已有文件时返回内容差异（diff）。';
    }

    public function schema(): array
    {
        return $this->inner->schema();
    }

    public function execute(array $input): string
    {
        $old = null;
        $path = trim((string) ($input['path'] ?? ''));
        if ($path !== '') {
            $abs = $this->existingFile($path);
            if ($abs !== null) {
                if ((filesize($abs) ?: 0) > self::MAX_DIFF_BYTES) {
                    $old = false; // 标记：旧文件过大，跳过 diff
                } else {
                    $old = (string) file_get_contents($abs);
                }
            }
        }

        $result = $this->inner->execute($input);
        if ($old === false) {
            return $result . "\n（原文件过大，跳过 diff 展示）";
        }

        $diff = DiffRenderer::render((string) $old, (string) ($input['content'] ?? ''));
        return $diff !== '' ? $result . "\n" . $diff : $result;
    }

    /** 解析到工作目录内的已存在文件；不存在或越界返回 null */
    private function existingFile(string $path): ?string
    {
        $abs = str_starts_with($path, '/') ? $path : $this->workspace . '/' . ltrim($path, './');
        $real = realpath($abs);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $root = rtrim($this->workspace, '/');
        if ($real !== $root && !str_starts_with($real, $root . '/')) {
            return null;
        }
        return $real;
    }
}
