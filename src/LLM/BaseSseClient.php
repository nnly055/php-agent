<?php

declare(strict_types=1);

namespace PhpAgent\LLM;

use PhpAgent\Config;

/**
 * 基于 curl + PHP Fiber 的 SSE 流式 POST 基类。
 *
 * curl 的 WRITEFUNCTION 是回调驱动的，generator 无法直接 yield 出去——
 * 这里用 Fiber 反转控制流：WRITEFUNCTION 里挂起 Fiber，外层循环消费 chunk 后再 resume。
 * PHP 8.1+ 原生支持 Fiber，无需任何扩展。
 */
abstract class BaseSseClient implements LLMClient
{
    protected SseStream $sse;

    public function __construct(protected Config $config)
    {
        $this->sse = new SseStream();
    }

    /** 逐条 yield SSE 的 "data:" 载荷 */
    protected function postSse(string $url, array $headers, array $body): \Generator
    {
        $this->requireApiKey();

        $pending = null;
        $transportError = null;
        $aborted = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json', 'Accept: text/event-stream'],
                $headers
            ),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 0, // 流式响应可能持续数分钟，不设总超时
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$pending, &$aborted) {
                if ($aborted) {
                    return 0; // 主动中止传输
                }
                $pending = $chunk;
                \Fiber::suspend();
                return strlen($chunk);
            },
            // 空闲时也约每秒触发一次，保证 abort 请求在无数据到达时也能生效
            CURLOPT_XFERINFOFUNCTION => function () use (&$aborted) {
                return $aborted ? 1 : 0;
            },
            CURLOPT_NOPROGRESS => false,
        ]);

        $fiber = new \Fiber(function () use ($ch, &$transportError, &$aborted) {
            $ok = curl_exec($ch);
            if ($ok === false && !$aborted) {
                $transportError = curl_error($ch);
            }
        });
        $fiber->start();

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($status >= 400) {
            // 非 2xx：把剩余 body 收完再抛，方便展示完整错误信息
            $errorBody = '';
            while (!$fiber->isTerminated()) {
                $errorBody .= (string) $pending;
                $pending = null;
                $fiber->resume();
            }
            curl_close($ch);
            throw new LLMException($this->describeHttpError($status, $errorBody));
        }

        try {
            while (!$fiber->isTerminated()) {
                $chunk = $pending;
                $pending = null;
                if (is_string($chunk) && $chunk !== '') {
                    foreach ($this->sse->feed($chunk) as $payload) {
                        yield $payload;
                    }
                }
                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                }
            }
        } finally {
            // 消费契约：本生成器应被调用方消费到自然结束（正常/异常展开的 finally 里 drain 均合法）。
            // 若仍被挂起中途销毁（调用方违约），PHP 禁止在生成器销毁上下文切 Fiber，
            // 此时清理只能放弃——吞掉错误避免掩盖原异常，代价是泄漏一个 curl 句柄。
            try {
                if (!$fiber->isTerminated()) {
                    $aborted = true;
                    while (!$fiber->isTerminated()) {
                        $fiber->resume();
                    }
                }
                curl_close($ch);
            } catch (\Throwable) {
                // 清理失败：放弃句柄，不影响业务异常的传播
            }
        }

        if ($transportError !== null) {
            throw new LLMException('HTTP 传输错误: ' . $transportError);
        }
    }

    protected function requireApiKey(): void
    {
        if ($this->config->apiKey() === '') {
            throw new LLMException('未配置 API key：请在项目根目录 .env 里设置 AGENT_API_KEY（参考 .env.example）。');
        }
    }

    protected function describeHttpError(int $status, string $body): string
    {
        $json = json_decode($body, true);
        $message = null;
        if (is_array($json)) {
            $message = $json['error']['message'] ?? $json['message'] ?? null;
        }
        $message ??= mb_substr(trim($body), 0, 300) ?: '(空响应体)';
        return "HTTP {$status}: {$message}";
    }
}
