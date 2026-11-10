<?php

declare(strict_types=1);

namespace PhpAgent\Tools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 递归正则搜索：在目录内按行匹配 PCRE 模式，输出 path:line:text。
 */
final class GrepTool extends WorkspaceTool
{
    private const MAX_MATCHES = 200;
    private const EXCLUDED_DIRS = ['.git', 'node_modules', '.idea', '.vscode'];

    public function name(): string
    {
        return 'grep';
    }

    public function description(): string
    {
        return '在工作目录内递归搜索代码，按行匹配正则表达式（PCRE），返回 path:line:text。适合找函数定义、调用点。';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => ['type' => 'string', 'description' => 'PCRE 正则（不带定界符），如 "function\\s+run"'],
                'path' => ['type' => 'string', 'description' => '搜索目录，默认工作目录根'],
                'include' => ['type' => 'string', 'description' => '文件名过滤（fnmatch 通配），如 "*.php"'],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $input): string
    {
        $pattern = (string) ($input['pattern'] ?? '');
        if ($pattern === '') {
            throw new \InvalidArgumentException('pattern 不能为空');
        }
        $regex = '/' . str_replace('/', '\/', $pattern) . '/';

        $dir = $this->resolve((string) ($input['path'] ?? '.'));
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("不是目录: {$this->display($dir)}");
        }
        $include = (string) ($input['include'] ?? '');

        $root = rtrim($this->workspace, '/');
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $matches = [];
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if (count($matches) >= self::MAX_MATCHES) {
                break;
            }
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            $parts = explode('/', substr($path, strlen($root) + 1));
            foreach ($parts as $part) {
                if (in_array($part, self::EXCLUDED_DIRS, true)) {
                    continue 2;
                }
            }
            if ($include !== '' && !fnmatch($include, $file->getFilename())) {
                continue;
            }
            if (($file->getSize() ?: 0) > 1024 * 1024) {
                continue;
            }
            $content = @file_get_contents($path);
            if ($content === false || str_contains(substr($content, 0, 8192), "\0")) {
                continue; // 二进制文件跳过
            }

            foreach (preg_split('/\r?\n/', $content) as $ln => $line) {
                if (preg_match($regex, $line) === 1) {
                    $rel = implode('/', $parts);
                    $matches[] = sprintf('%s:%d: %s', $rel, $ln + 1, trim(mb_substr($line, 0, 200)));
                    if (count($matches) >= self::MAX_MATCHES) {
                        break;
                    }
                }
            }
        }

        if ($matches === []) {
            return '(无匹配)';
        }
        $suffix = count($matches) >= self::MAX_MATCHES ? "\n…（已达 {$this->limitNote()}）" : '';
        return "共 " . count($matches) . " 处匹配:\n" . implode("\n", $matches) . $suffix;
    }

    private function limitNote(): string
    {
        return self::MAX_MATCHES . ' 条上限';
    }
}
