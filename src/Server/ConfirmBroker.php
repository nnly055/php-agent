<?php

declare(strict_types=1);

namespace PhpAgent\Server;

use PhpAgent\LLM\StreamEvent;
use React\Async;
use React\EventLoop\Loop;
use React\Promise\Deferred;

/**
 * HTTP 工具授权协调器（server ask 模式）。
 *
 * request() 作为 ConfirmHook 的 prompt：发 confirm_request SSE 事件 → 挂起当前
 * Fiber 协程（React\Async\await，不阻塞事件循环）→ 等 POST /confirm 经 resolve() 唤醒。
 * 超时（默认 120s）或流断开（discardActive）自动按拒绝（n）处理。
 *
 * MVP 假定每会话同一时刻只有一条流，故至多一个 pending confirm。
 */
final class ConfirmBroker
{
    /** @var array<string, Deferred> */
    private array $pending = [];
    /** @var array<string, \React\EventLoop\TimerInterface> */
    private array $timers = [];
    private ?\Closure $emit = null;
    private ?string $activeId = null;

    public function __construct(
        private readonly float $timeoutSeconds = 120.0,
        private readonly ?string $workspace = null,
    ) {
    }

    /** @param \Closure(StreamEvent): void $emit */
    public function setEmitter(\Closure $emit): void
    {
        $this->emit = $emit;
    }

    public function pendingId(): ?string
    {
        return $this->activeId;
    }

    /** ConfirmHook prompt：返回 'y' | 'n' | 'a' */
    public function request(string $tool, array $input): string
    {
        $id = uniqid('cf_', true);
        $preview = $this->buildPreview($tool, $input);

        // 先注册 pending/activeId/定时器，再发事件：emitter 若同步 resolve() 也能命中
        $deferred = new Deferred();
        $this->pending[$id] = $deferred;
        $this->activeId = $id;
        $this->timers[$id] = Loop::addTimer($this->timeoutSeconds, function () use ($id): void {
            $this->settle($id, 'n');
        });

        // emitter 同步抛错时也要清理，否则 pending/timer/activeId 永久泄漏
        try {
            if ($this->emit !== null) {
                ($this->emit)(StreamEvent::confirmRequest($id, $tool, $input, $preview));
            }
        } catch (\Throwable $e) {
            $this->cleanup($id);
            throw $e;
        }

        try {
            $decision = Async\await($deferred->promise());
        } finally {
            $this->cleanup($id);
        }
        return in_array($decision, ['y', 'n', 'a'], true) ? $decision : 'n';
    }

    /** POST /confirm/{id}：命中 pending 返 true，未知/过期返 false */
    public function resolve(string $id, string $decision): bool
    {
        if (!isset($this->pending[$id])) {
            return false;
        }
        $this->settle($id, $decision);
        return true;
    }

    /** SSE 流断开：把当前 pending 按拒绝清理，防泄漏 */
    public function discardActive(): void
    {
        if ($this->activeId !== null) {
            $this->settle($this->activeId, 'n');
        }
    }

    private function settle(string $id, string $decision): void
    {
        if (isset($this->pending[$id])) {
            $this->pending[$id]->resolve($decision);
        }
    }

    private function cleanup(string $id): void
    {
        unset($this->pending[$id]);
        if (isset($this->timers[$id])) {
            Loop::cancelTimer($this->timers[$id]);
            unset($this->timers[$id]);
        }
        if ($this->activeId === $id) {
            $this->activeId = null;
        }
    }

    private function buildPreview(string $tool, array $input): string
    {
        if ($tool === 'run_command') {
            return (string) ($input['command'] ?? '');
        }
        if ($tool !== 'write_file') {
            return '';
        }
        $path = trim((string) ($input['path'] ?? ''));
        $content = (string) ($input['content'] ?? '');
        if ($path === '') {
            return '';
        }
        // 相对路径基于 workspace 拼绝对路径；定位不到则给新文件摘要。
        $abs = $path;
        if (!str_starts_with($path, '/') && $this->workspace !== null) {
            $abs = rtrim($this->workspace, '/') . '/' . $path;
        }
        if (!is_file($abs)) {
            $lines = substr_count($content, "\n") + 1;
            return '新文件 · ' . $lines . ' 行 · ' . strlen($content) . ' bytes';
        }
        if (!class_exists(\PhpAgent\Plugin\Diff\DiffRenderer::class)) {
            return '';
        }
        if ((filesize($abs) ?: 0) > \PhpAgent\Plugin\Diff\DiffRenderer::MAX_DIFF_BYTES) {
            return '(原文件过大，跳过预览)';
        }
        $old = (string) file_get_contents($abs);
        $diff = \PhpAgent\Plugin\Diff\DiffRenderer::render($old, $content, 40);
        return $this->stripAnsi($diff);
    }

    private function stripAnsi(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }
}