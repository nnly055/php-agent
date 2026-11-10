<?php

declare(strict_types=1);

namespace PhpAgent\Tools;

use PhpAgent\Agent\Tool;

/**
 * 工具基类：所有路径都解析并限制在工作目录内（软沙箱，防止模型误操作外部文件）。
 */
abstract class WorkspaceTool implements Tool
{
    protected string $workspace;

    public function __construct(string $workspace)
    {
        // macOS 等 symlink 环境（/var → /private/var）必须先归一化，否则沙箱路径比对会误判
        $real = realpath($workspace);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException("工作目录不存在: {$workspace}");
        }
        $this->workspace = rtrim($real, '/');
    }

    /** 把模型给的路径解析成工作目录内的绝对路径；多级目录不存在时逐级向上找已存在祖先再拼回 */
    protected function resolve(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('路径不能为空');
        }
        $abs = str_starts_with($path, '/') ? $path : $this->workspace . '/' . $path;

        $real = realpath($abs);
        if ($real !== false) {
            $this->assertInside($real, $path);
            return $real;
        }

        // 路径尚不存在（write_file 常见）：找最近的已存在祖先目录，把剩余片段拼回去
        $parts = explode('/', ltrim($abs, '/'));
        $tail = [];
        $existing = null;
        while ($parts !== []) {
            $candidate = '/' . implode('/', $parts);
            if (is_dir($candidate)) {
                $existing = realpath($candidate);
                break;
            }
            array_unshift($tail, array_pop($parts));
        }
        if ($existing === null) {
            throw new \InvalidArgumentException("路径不存在: {$path}");
        }

        $real = rtrim((string) $existing, '/') . '/' . implode('/', $tail);
        $this->assertInside($real, $path);
        return $real;
    }

    private function assertInside(string $real, string $original): void
    {
        $root = rtrim($this->workspace, '/');
        if ($real !== $root && !str_starts_with($real, $root . '/')) {
            throw new \InvalidArgumentException("路径超出工作目录（{$root}）: {$original}");
        }
    }

    /** 工作目录内的相对路径（给返回值展示用） */
    protected function display(string $absolute): string
    {
        $root = rtrim($this->workspace, '/');
        return $absolute === $root ? '.' : ltrim(substr($absolute, strlen($root)), '/');
    }
}
