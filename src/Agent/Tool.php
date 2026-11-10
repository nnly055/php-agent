<?php

declare(strict_types=1);

namespace PhpAgent\Agent;

/**
 * 工具契约：LLM 通过 function calling 调用的最小单元。
 * execute() 返回文本结果；抛异常会被 ToolRegistry 捕获并转成 "ERROR: ..." 文本回传给模型。
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /** JSON Schema（Anthropic input_schema / OpenAI parameters 通用） */
    public function schema(): array;

    /** @param array $input 模型给出的入参 */
    public function execute(array $input): string;
}
