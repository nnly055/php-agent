<?php

declare(strict_types=1);

namespace PhpAgent\Hook;

/**
 * 审计日志钩子：把工具调用（入参）与结果（截断）追加写入 JSONL。
 * 默认由内置 AuditPlugin 装配到 ~/.php-agent/sessions/audit.jsonl。
 */
final class AuditLogHook implements AgentHook
{
    public function __construct(private readonly string $file)
    {
    }

    public function events(): array
    {
        return ['before_tool_call', 'after_tool_result'];
    }

    public function handle(string $event, array $payload): void
    {
        $row = [
            'ts' => date('c'),
            'event' => $event,
            'tool' => (string) ($payload['tool'] ?? ''),
            'input' => $payload['input'] ?? [],
        ];
        if ($event === 'after_tool_result') {
            $row['result'] = mb_substr((string) ($payload['result'] ?? ''), 0, 500);
        }
        @file_put_contents($this->file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
}
