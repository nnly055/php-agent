<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/server 传输层：请求体缓冲上限（真实 socket）。
 *
 * handle() 级测试看不到 HttpServer 的中间件栈（默认自动注入 64KiB 缓冲，
 * 超限 body 被静默清空），必须起真实 `php bin/server` 进程走 socket：
 *  - ≈200KB 的 JSON body 可达 /agent（图片 base64 内联的前提）
 *  - 超硬上限（40MB）返回 413
 */
final class ServerBodyLimitTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    private string $workspace = '';

    private string $port = '';

    protected function setUp(): void
    {
        // Config::workspace() 要求目录已存在；fake provider 一回合会写入 demo/hello.php，tearDown 递归删除
        $this->workspace = sys_get_temp_dir() . '/php-agent-bodylimit-' . uniqid();
        self::assertNotFalse(mkdir($this->workspace, 0777, true));
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            @fclose($this->serverPipes[0]);
            @fclose($this->serverPipes[1]);
            @fclose($this->serverPipes[2]);
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        $this->removeDir($this->workspace);
    }

    /** 起 php bin/server（AGENT_SERVER_PORT=0 自动分配），就绪轮询 stdout 的 AGENT_LISTENING port=N（模式同 HttpTransportTest::startServer，5 秒超时） */
    private function startServer(): void
    {
        $proc = proc_open(
            [PHP_BINARY, 'bin/server'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__), // 项目根
            array_merge(getenv() ?: [], [
                'AGENT_PROVIDER' => 'fake',
                'AGENT_SESSION_STORE' => 'memory',
                'AGENT_SERVER_PORT' => '0',
                'AGENT_WORKSPACE' => $this->workspace,
                'AGENT_PERMISSIONS' => 'off', // 默认 ask 会因 fake 回合的 write_file 卡在 ConfirmBroker 确认（120s 超时）
            ])
        );
        if (!is_resource($proc)) {
            self::fail('无法启动 bin/server');
        }
        $this->server = $proc;
        $this->serverPipes = $pipes;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 8192);
            if (is_string($chunk) && preg_match('/AGENT_LISTENING port=(\d+)/', $chunk, $m)) {
                $this->port = $m[1];
                return;
            }
            fread($pipes[2], 8192); // 顺手排空 stderr，防止其管道缓冲写满导致服务端阻塞
            usleep(20000);
        }
        self::fail('bin/server 未在 5 秒内监听');
    }

    /** 递归删除临时 workspace */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return array{0: int, 1: string} [状态码, 响应体]；ignore_errors 保 4xx/5xx 可读，状态码取自 $http_response_header[0] */
    private function post(string $path, string $body): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, $ctx);
        $statusLine = $http_response_header[0] ?? '';
        self::assertSame(1, preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m), "无 HTTP 状态行（连接被重置？）: {$statusLine}");
        return [(int) $m[1], (string) $resp];
    }

    public function testBodyOverDefaultLimitReachesHandler(): void
    {
        // ~200KB 的 message（旧 64KiB 上限会静默清空 body → 误导性 400）
        $body = json_encode(['message' => str_repeat('a', 200 * 1024), 'session_id' => 't1'], JSON_THROW_ON_ERROR);
        [$status, $respBody] = $this->post('/agent', $body);
        self::assertSame(200, $status, "响应体: {$respBody}"); // fake provider 直接给最终回答
    }

    public function testBodyOverHardLimitReturns413(): void
    {
        $body = json_encode(['message' => str_repeat('a', 41 * 1024 * 1024)], JSON_THROW_ON_ERROR);
        [$status, $respBody] = $this->post('/agent', $body);
        self::assertSame(413, $status, "响应体: {$respBody}");
    }
}
