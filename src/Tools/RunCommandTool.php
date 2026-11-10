<?php

declare(strict_types=1);

namespace PhpAgent\Tools;

/**
 * 执行 shell 命令：工作目录内运行，带超时保护与输出截断。
 * stdin 直接给 EOF，避免交互式命令卡死。
 */
final class RunCommandTool extends WorkspaceTool
{
    private const MAX_OUTPUT = 32768;

    public function name(): string
    {
        return 'run_command';
    }

    public function description(): string
    {
        return '在工作目录中执行一条 shell 命令，返回 stdout/stderr 与退出码。用于运行测试、编译、查看 git 状态等。避免交互式命令。';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => '要执行的 shell 命令'],
                'timeout_sec' => ['type' => 'integer', 'description' => '超时秒数，默认 60，最大 300'],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $input): string
    {
        $command = trim((string) ($input['command'] ?? ''));
        if ($command === '') {
            throw new \InvalidArgumentException('命令不能为空');
        }
        $timeout = min(300, max(1, (int) ($input['timeout_sec'] ?? 60)));

        $proc = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->workspace
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException("无法启动命令: {$command}");
        }

        fclose($pipes[0]); // stdin 给 EOF，防交互命令挂起
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $start = microtime(true);
        $timedOut = false;
        $exitCode = null;

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $n = @stream_select($read, $write, $except, 0, 200000);

            if ($n === false) {
                break; // select 失败（管道已关）
            }
            if ($n > 0) {
                foreach ($read as $r) {
                    $data = (string) fread($r, 65536);
                    if ($r === $pipes[1]) {
                        $out .= $data;
                    } else {
                        $err .= $data;
                    }
                }
            }

            if (microtime(true) - $start > $timeout) {
                $timedOut = true;
                proc_terminate($proc, 9);
                break;
            }

            $status = proc_get_status($proc);
            if (!$status['running'] && @feof($pipes[1]) && @feof($pipes[2])) {
                $exitCode = $status['exitcode']; // proc_get_status 的 exitcode 只在首次 running=false 时有效
                break;
            }
        }

        // 收尾残留输出
        while (($data = (string) fread($pipes[1], 65536)) !== '') {
            $out .= $data;
        }
        while (($data = (string) fread($pipes[2], 65536)) !== '') {
            $err .= $data;
        }

        $closed = proc_close($proc);
        $code = $exitCode ?? $closed;

        $result = '';
        if (trim($out) !== '') {
            $result .= rtrim($out) . "\n";
        }
        if (trim($err) !== '') {
            $result .= "[stderr]\n" . rtrim($err) . "\n";
        }
        $result = rtrim($result);
        if ($result === '') {
            $result = '(无输出)';
        }
        if (mb_strlen($result) > self::MAX_OUTPUT) {
            $result = mb_substr($result, 0, self::MAX_OUTPUT) . "\n…（输出过长，已截断）";
        }
        if ($timedOut) {
            $result .= "\n⚠ 超过 {$timeout}s 被强制终止";
        }

        $body = $result . "\n[exit code: {$code}]";
        // 非零退出统一加 ERROR 前缀，模型和 UI 都能立刻识别失败
        return $code !== 0 ? "ERROR: 命令退出码 {$code}\n" . $body : $body;
    }
}
