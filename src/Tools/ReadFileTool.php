<?php

declare(strict_types=1);

namespace PhpAgent\Tools;

/**
 * 读文件（带行号），支持 offset/limit，用于查看代码。
 */
final class ReadFileTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'read_file';
    }

    public function description(): string
    {
        return '读取工作目录内某个文件的内容（带行号）。大文件可用 offset/limit 分段读取。';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => '文件路径（相对工作目录或绝对路径）'],
                'offset' => ['type' => 'integer', 'description' => '起始行号（1-based），默认 1'],
                'limit' => ['type' => 'integer', 'description' => '读取行数，默认 500，最大 2000'],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input): string
    {
        $file = $this->resolve((string) ($input['path'] ?? ''));
        if (!is_file($file)) {
            throw new \InvalidArgumentException("不是文件或不存在: {$this->display($file)}");
        }

        $size = filesize($file) ?: 0;
        if ($size > 512 * 1024) {
            return "ERROR: 文件过大（{$size} bytes），超过 512KB 读取上限: {$this->display($file)}";
        }

        $offset = max(1, (int) ($input['offset'] ?? 1));
        $limit = min(2000, max(1, (int) ($input['limit'] ?? 500)));

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("无法读取文件: {$this->display($file)}");
        }

        $total = count($lines);
        $slice = array_slice($lines, $offset - 1, $limit);

        if ($slice === []) {
            return "(文件共 {$total} 行，offset={$offset} 超出范围)";
        }

        $out = '';
        foreach ($slice as $i => $line) {
            $out .= sprintf("%6d  %s\n", $offset + $i, $line);
        }
        $out = rtrim($out);

        if ($offset + count($slice) - 1 < $total) {
            $out .= "\n…（共 {$total} 行，还有更多内容，可继续用 offset 读取）";
        }
        return $out;
    }
}
