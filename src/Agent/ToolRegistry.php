<?php

declare(strict_types=1);

namespace PhpAgent\Agent;

/**
 * 工具注册表：向 LLM 暴露规格、执行调用、兜底异常。
 */
final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function register(Tool $tool): self
    {
        $this->tools[$tool->name()] = $tool;
        return $this;
    }

    /** @return string[] 已注册工具名 */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /** @return array 工具规格（Anthropic 形态，OpenAI 客户端会自行转换） */
    public function specs(): array
    {
        $specs = [];
        foreach ($this->tools as $tool) {
            $specs[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->schema(),
            ];
        }
        return $specs;
    }

    public function execute(string $name, array $input): string
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return "ERROR: 未注册的工具 '{$name}'（可用: " . implode(', ', $this->names()) . '）';
        }
        try {
            return $tool->execute($input);
        } catch (\Throwable $e) {
            return 'ERROR: ' . $e->getMessage();
        }
    }
}
