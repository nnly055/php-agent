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
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$pending) {
                $pending = $chunk;
                \Fiber::suspend();
                return strlen($chunk);
            },
        ]);

        $fiber = new \Fiber(function () use ($ch, &$transportError) {
            $ok = curl_exec($ch);
            if ($ok === false) {
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
            curl_close($ch);
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
