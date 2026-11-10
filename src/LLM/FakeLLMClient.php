<?php

declare(strict_types=1);

namespace PhpAgent\LLM;

/**
 * 离线假客户端：按预置脚本逐轮回放 StreamEvent。
 * 用途：① 单元测试 agent 循环（不需要网络/API key）② `php bin/agent --fake` 离线演示完整流程。
 */
final class FakeLLMClient implements LLMClient
{
    private int $turnIndex = 0;

    /** @param array<int, array<int, StreamEvent>> $script 每个元素是一轮流式事件 */
    public function __construct(private array $script)
    {
    }

    public function stream(array $messages, array $tools, string $system): \Generator
    {
        $turn = $this->script[$this->turnIndex++]
            ?? [StreamEvent::text('(fake) 演示脚本已播完，配置真实 provider 请编辑 .env。')];
        foreach ($turn as $event) {
            yield $event;
        }
    }

    /** 端到端离线演示：写文件 → 运行验证 → 总结 */
    public static function demo(): self
    {
        return new self([
            [
                StreamEvent::text("好的，我来演示一次完整的 agent 流程：先创建一个 PHP 文件，再运行验证。\n\n"),
                StreamEvent::toolStart('fake-1', 'write_file'),
                StreamEvent::toolEnd('fake-1', 'write_file', [
                    'path' => 'demo/hello.php',
                    'content' => "<?php\n\necho \"Hello from PHP Agent!\" . PHP_EOL;\n",
                ]),
                StreamEvent::end('tool_use'),
            ],
            [
                StreamEvent::text("文件已创建，运行验证一下：\n"),
                StreamEvent::toolStart('fake-2', 'run_command'),
                StreamEvent::toolEnd('fake-2', 'run_command', ['command' => 'php demo/hello.php']),
                StreamEvent::end('tool_use'),
            ],
            [
                StreamEvent::text("运行成功，输出 `Hello from PHP Agent!`。\n\n✅ 离线演示完成。把 .env 配好真实 provider（GLM / DeepSeek / Kimi / OpenAI / Anthropic 任选其一），agent 就能真正干活了。"),
            ],
        ]);
    }
}
