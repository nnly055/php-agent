<?php

declare(strict_types=1);

namespace PhpAgent\Tools;

/**
 * 列目录：单层浏览，目录带 / 后缀。
 */
final class ListDirTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'list_dir';
    }

    public function description(): string
    {
        return '列出工作目录内某个目录的下一级内容（目录以 / 结尾，附文件大小）。';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => '目录路径，默认工作目录根'],
            ],
        ];
    }

    public function execute(array $input): string
    {
        $dir = $this->resolve((string) ($input['path'] ?? '.'));
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("不是目录: {$this->display($dir)}");
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            throw new \RuntimeException("无法读取目录: {$this->display($dir)}");
        }

        $dirs = [];
        $files = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dir . '/' . $name;
            if (is_dir($full)) {
                $dirs[] = sprintf('  %s/', $name);
            } else {
                $files[] = sprintf('  %-42s %s', $name, $this->humanSize(@filesize($full) ?: 0));
            }
        }

        $lines = array_merge($dirs, $files);
        if ($lines === []) {
            return '(空目录)';
        }
        return $this->display($dir) . ":\n" . implode("\n", $lines);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
