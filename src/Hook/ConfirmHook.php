<?php

declare(strict_types=1);

namespace PhpAgent\Hook;

/**
 * 工具执行授权钩子（对标 Claude Code 权限模式）。
 *
 * 规则：
 * - 只读工具（read_file / list_dir / grep）自动放行
 * - 其余工具（含第三方插件注册的，默认按需授权）先问用户
 * - 用户答 'a'（always）后本次进程内该工具不再询问
 * - prompt 回调返回 'y' | 'n' | 'a'
 */
final class ConfirmHook implements AgentHook
{
    public const READ_ONLY = ['read_file', 'list_dir', 'grep'];

    /** @var string[] 本进程内被永久允许的工具 */
    private array $allowedTools = [];

    /** @param \Closure(string $tool, array $input): string $prompt */
    public function __construct(private readonly \Closure $prompt)
    {
    }

    public function events(): array
    {
        return ['before_tool_call'];
    }

    public function handle(string $event, array $payload): void
    {
        $tool = (string) ($payload['tool'] ?? '');

        if (in_array($tool, $this->allowedTools, true)) {
            return;
        }
        if (in_array($tool, self::READ_ONLY, true)) {
            return;
        }

        $answer = ($this->prompt)($tool, is_array($payload['input'] ?? null) ? $payload['input'] : []);

        if ($answer === 'a') {
            $this->allowedTools[] = $tool;
            return;
        }
        if ($answer !== 'y') {
            throw new HookVetoException('用户未授权');
        }
    }
}
