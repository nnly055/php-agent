<?php

declare(strict_types=1);

namespace PhpAgent\LLM;

/**
 * 流式事件——LLM 客户端、Agent 循环、UI 之间的统一协议。
 * data 字段按 type 携带不同内容：
 *   text_delta        {text}
 *   thinking_delta    {text}        推理模型（GLM/DeepSeek-R1）的思维链
 *   tool_use_start    {toolId, toolName}
 *   tool_input_delta  {toolId, jsonChunk}
 *   tool_use_end      {toolId, toolName, input}
 *   tool_result       {toolName, result}
 *   message_end       {stopReason}
 *   error             {message}
 *   notice            {text}         钩子向 UI 的通知（如上下文压缩）
 */
final class StreamEvent
{
    private function __construct(public readonly string $type, public readonly array $data)
    {
    }

    public static function text(string $text): self
    {
        return new self('text_delta', ['text' => $text]);
    }

    public static function thinking(string $text): self
    {
        return new self('thinking_delta', ['text' => $text]);
    }

    public static function toolStart(string $toolId, string $toolName): self
    {
        return new self('tool_use_start', ['toolId' => $toolId, 'toolName' => $toolName]);
    }

    public static function toolInputDelta(string $toolId, string $jsonChunk): self
    {
        return new self('tool_input_delta', ['toolId' => $toolId, 'jsonChunk' => $jsonChunk]);
    }

    public static function toolEnd(string $toolId, string $toolName, array $input): self
    {
        return new self('tool_use_end', ['toolId' => $toolId, 'toolName' => $toolName, 'input' => $input]);
    }

    public static function toolResult(string $toolName, string $result): self
    {
        return new self('tool_result', ['toolName' => $toolName, 'result' => $result]);
    }

    public static function end(?string $stopReason = null): self
    {
        return new self('message_end', ['stopReason' => $stopReason]);
    }

    /** 会话最终回答（服务端在 SSE 结束时额外发出，前端用来做消息收尾） */
    public static function finalAnswer(string $text): self
    {
        return new self('final', ['text' => $text]);
    }

    public static function error(string $message): self
    {
        return new self('error', ['message' => $message]);
    }

    /** 钩子向 UI 的通知（如上下文压缩、审计提示） */
    public static function notice(string $text): self
    {
        return new self('notice', ['text' => $text]);
    }
}
