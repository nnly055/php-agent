<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Session\Store\JsonFileSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * CLI e2e：/resume 恢复「启动同 id」的已有会话必须按 meta 重绑模型。
 *
 * 回归（终审 Critical #1）：启动时 AGENT_SESSION_ID 指向已有会话，CLI 提示
 * 「/resume 恢复继续」——此时 sessionId 与启动值相同，若切换钩子只比对 id 变化，
 * 恢复的模型选择会被静默忽略（生效 client ≠ meta 记录）。
 * proc_open 驱动 bin/agent，fake provider 离线跑通全链路。
 */
final class CliResumeRebindTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-agent-cli-rebind-' . uniqid();
        mkdir($this->dir . '/sessions', 0777, true);
    }

    protected function tearDown(): void
    {
        // 会话文件与目录随 uniqid 隔离，直接清理
        @unlink($this->dir . '/sessions/default.json');
        @rmdir($this->dir . '/sessions');
        @rmdir($this->dir);
    }

    public function testResumeSameIdSessionRebindsSavedModel(): void
    {
        // 预置会话：历史 + 模型选择 b/m2（档 b）+ 强度 max
        $store = new JsonFileSessionStore($this->dir . '/sessions');
        $store->save('default', [['role' => 'user', 'content' => [['type' => 'text', 'text' => '早前任务']]]],
            ['profileId' => 'b', 'model' => 'm2', 'effort' => 'max']);

        $env = array_merge(getenv() ?: [], [
            'AGENT_SESSION_DIR' => $this->dir . '/sessions',
            'AGENT_SESSION_STORE' => 'json',
            'AGENT_SESSION_ID' => 'default',
            'AGENT_MCP' => 'off',
            'AGENT_PLUGINS' => 'off',
            'AGENT_PERMISSIONS' => 'off',
            'AGENT_AUDIT' => 'off',
            'AGENT_PROFILES' => '[{"id":"a","name":"档A","provider":"fake","models":["m1"]},{"id":"b","name":"档B","provider":"fake","models":["m2"]}]',
            'AGENT_DEFAULT_PROFILE' => 'a',
        ]);
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/../bin/agent'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $env
        );
        $this->assertIsResource($proc);
        fwrite($pipes[0], "/resume\n/exit\n");
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        // /resume 后必须恢复 meta 记录的档与强度（而非默认档 a/m1/off）
        self::assertStringContainsString('b / m2', $out);
        self::assertStringContainsString('max', $out);
    }
}
