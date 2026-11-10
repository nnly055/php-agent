<?php

declare(strict_types=1);

namespace PhpAgent\Tools;

/**
 * 写文件：内容整体覆盖写入，自动创建父目录。
 */
final class WriteFileTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'write_file';
    }

    public function description(): string
    {
        return '把内容写入工作目录内的文件（整体覆盖）。父目录不存在会自动创建。新建文件或全量重写时使用。';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => '文件路径（相对工作目录或绝对路径）'],
                'content' => ['type' => 'string', 'description' => '要写入的完整内容'],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $input): string
    {
        $file = $this->resolve((string) ($input['path'] ?? ''));
        $content = (string) ($input['content'] ?? '');

        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException("无法创建目录: {$this->display($dir)}");
        }

        $bytes = file_put_contents($file, $content);
        if ($bytes === false) {
            throw new \RuntimeException("写入失败: {$this->display($file)}");
        }
        return "OK: 已写入 {$this->display($file)}（{$bytes} bytes）";
    }
}
