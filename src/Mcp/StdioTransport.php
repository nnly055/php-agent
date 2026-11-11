<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * stdio 传输：MCP server 以子进程运行，stdin/stdout 按行交换 JSON-RPC（规范要求单行消息）。
 * stderr 捕获进缓冲区，失败时随异常输出辅助定位。
 */
final class StdioTransport implements Transport
{
    private const MAX_LINE_BYTES = 10 * 1024 * 1024; // 防失控输出
    private const STDERR_TAIL_BYTES = 8192;

    /** @var resource|null */
    private $process;

    /** @var resource stdin 写端 */
    private $stdin;

    /** @var resource stdout 读端（阻塞） */
    private $stdout;

    /** @var resource stderr 读端（非阻塞，随读随排空防管道写满死锁） */
    private $stderr;

    private string $stderrBuf = '';

    /** @var array<int, array> 最近收到的 notification（诊断用） */
    private array $recentNotifications = [];

    /** @param array<int, string> $args @param array<string, string> $env */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly array $env = [],
    ) {
        // 显式构造子进程环境：PHP 默认 variables_order 不含 E，$_ENV 常为空，
        // 而 proc_open 一旦传 env 就是「整体替换」——必须自己带上 PATH/HOME 等，
        // 否则 npx/uvx 之类在子进程里找不到
        $base = [];
        foreach (['PATH', 'HOME', 'TMPDIR', 'USER', 'SHELL', 'LANG', 'TERM'] as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                $base[$k] = $v;
            }
        }
        $childEnv = array_merge($base, is_array($_ENV) ? $_ENV : [], $this->env);

        $proc = proc_open(
            [$command, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $childEnv
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException("无法启动 MCP server 进程: {$command}");
        }
        $this->process = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stderr, false);
    }

    public function request(array $payload, float $timeout): array
    {
        if (!array_key_exists('id', $payload)) {
            throw new \LogicException('request() 的 payload 必须含 id');
        }
        $this->sendLine($payload);
        $id = $payload['id'];
        $deadline = microtime(true) + $timeout;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException("MCP server '{$this->command}' 响应超时（{$timeout}s）");
            }
            $read = [$this->stdout, $this->stderr];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000));
            if ($selected === false) {
                throw new \RuntimeException('stream_select 失败');
            }
            if ($selected === 0) {
                continue; // 回顶部检查 deadline
            }
            foreach ($read as $stream) {
                if ($stream === $this->stdout) {
                    $line = stream_get_line($this->stdout, self::MAX_LINE_BYTES, "\n");
                    if ($line === false) {
                        if (!proc_get_status($this->process)['running']) {
                            throw new \RuntimeException("MCP server 进程已退出: {$this->stderrTail()}");
                        }
                        continue;
                    }
                    if ($line === '') {
                        continue;
                    }
                    $msg = json_decode($line, true);
                    if (!is_array($msg)) {
                        continue; // 非法行忽略
                    }
                    if (($msg['id'] ?? null) === $id) {
                        return $msg;
                    }
                    if (!array_key_exists('id', $msg)) {
                        $this->recentNotifications[] = $msg;
                        if (count($this->recentNotifications) > 10) {
                            array_shift($this->recentNotifications);
                        }
                    }
                } elseif ($stream === $this->stderr) {
                    $this->drainStderr();
                }
            }
        }
    }

    public function notify(array $payload): void
    {
        $this->sendLine($payload);
    }

    public function setProtocolVersion(string $version): void
    {
        // stdio 无请求头，协议版本仅由连接层校验，此处 no-op
    }

    public function recentNotifications(): array
    {
        return $this->recentNotifications;
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }
        @fclose($this->stdin);
        @fclose($this->stdout);
        @fclose($this->stderr);
        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
    }

    private function sendLine(array $payload): void
    {
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $written = @fwrite($this->stdin, $line);
        if ($written === false || $written < strlen($line)) {
            throw new \RuntimeException("MCP server '{$this->command}' stdin 写入失败（进程可能已退出）: {$this->stderrTail()}");
        }
    }

    private function drainStderr(): void
    {
        while (true) {
            $chunk = fread($this->stderr, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->stderrBuf .= $chunk;
        }
        if (strlen($this->stderrBuf) > self::STDERR_TAIL_BYTES) {
            $this->stderrBuf = substr($this->stderrBuf, -self::STDERR_TAIL_BYTES);
        }
    }

    private function stderrTail(): string
    {
        $tail = trim(substr($this->stderrBuf, -500));
        return $tail !== '' ? $tail : '(无 stderr 输出)';
    }
}
