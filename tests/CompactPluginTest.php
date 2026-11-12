<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Hook\HookPipeline;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Plugin\Compact\CompactPlugin;
use PhpAgent\Plugin\Compact\Compactor;
use PhpAgent\Server\ProfileRegistry;
use PHPUnit\Framework\TestCase;

// 插件类不经 composer autoload（项目插件由 plugin.php require），测试中显式引入
require_once dirname(__DIR__) . '/plugins/compact/src/CompactionResult.php';
require_once dirname(__DIR__) . '/plugins/compact/src/Compactor.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactCommand.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactHook.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactPlugin.php';

/**
 * compact 插件：钩子接线（估算→压缩→notes 写穿）、失败节流。
 * 全部走显式注入工厂；生产装配路径（null 参数 → Config 解析 + provider 回退）的覆盖见后续跟进。
 */
final class CompactPluginTest extends TestCase
{
    /** 低阈值 + 高压缩率脚本：让 FakeLLM 的摘要看起来"变小" */
    private function kernelWith(Compactor $compactor, int $contextTokens, float $threshold): AgentKernel
    {
        putenv('AGENT_WORKSPACE=' . sys_get_temp_dir());
        $kernel = new AgentKernel(\PhpAgent\Config::load(sys_get_temp_dir()));
        (new CompactPlugin(
            static fn (): Compactor => $compactor,
            $contextTokens,
            $threshold,
        ))->register($kernel);
        return $kernel;
    }

    private function bigHistory(): array
    {
        $turn = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => str_repeat('早期任务说明。', 50)]]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => str_repeat('已完成早期任务。', 50)]]],
        ];
        $history = [];
        for ($i = 0; $i < 4; $i++) {
            array_push($history, ...$turn);
        }
        $history[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '当前任务：继续']]];
        return $history;
    }

    public function testHookCompactsAndWritesThroughNotes(): void
    {
        $fake = new FakeLLMClient([[StreamEvent::text('早期完成了若干任务。'), StreamEvent::end('end_turn')]]);
        $kernel = $this->kernelWith(new Compactor($fake, 128000, 0.0), 200, 0.5); // 阈值 100 tokens 必然触发

        $messages = $this->bigHistory();
        $notes = [];
        $payload = ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1];
        HookPipeline::fire($kernel->hooks(), 'before_llm_request', $payload);

        // messages 写穿：只剩合成对 + 当前任务
        self::assertCount(3, $messages);
        self::assertSame('user', $messages[0]['role']);
        self::assertStringContainsString('<context-summary>', $messages[0]['content'][0]['text']);
        // notes 写穿：UI 能收到提示
        self::assertCount(1, $notes);
        self::assertStringContainsString('已将前', $notes[0]);
    }

    public function testHookSkipsWhenUnderThreshold(): void
    {
        $fake = new FakeLLMClient([]);
        $kernel = $this->kernelWith(new Compactor($fake, 10_000_000, 0.8), 10_000_000, 0.8);

        $messages = $this->bigHistory();
        $notes = [];
        $payload = ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1];
        HookPipeline::fire($kernel->hooks(), 'before_llm_request', $payload);

        self::assertCount(9, $messages); // 原样
        self::assertSame([], $notes);
    }

    public function testFailureLatchSkipsUntilNextTaskStart(): void
    {
        $broken = new class implements \PhpAgent\LLM\LLMClient {
            public function stream(array $messages, array $tools, string $system): \Generator
            {
                throw new \RuntimeException('无 key');
                yield;
            }
        };
        $kernel = $this->kernelWith(new Compactor($broken, 128000, 0.0), 200, 0.5);

        $messages = $this->bigHistory();
        $notes = [];
        $fire = static function () use (&$messages, &$notes): array {
            $payload = ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1];
            HookPipeline::fire($GLOBALS['kernel']->hooks(), 'before_llm_request', $payload);
            return $notes;
        };
        $GLOBALS['kernel'] = $kernel;

        // 第一次：尝试压缩 → 失败 → note + 进入节流
        $fire();
        self::assertCount(9, $messages);
        self::assertStringContainsString('压缩失败', $notes[0] ?? '');

        // 同任务推进（模拟第 2 轮迭代，尾条变为 tool_result）：节流生效，不再追加 note
        $messages[] = ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'x1', 'name' => 'read_file', 'input' => []]]];
        $messages[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'x1', 'content' => 'data']]];
        $countBefore = count($notes);
        $fire();
        self::assertCount($countBefore, $notes);

        // 新任务起点（尾条是普通 user 文本）：解除节流，再次尝试失败
        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '新任务来了']]];
        $fire();
        self::assertCount($countBefore + 1, $notes);

        unset($GLOBALS['kernel']);
    }

    public function testRegistersUnderNameCompact(): void
    {
        putenv('AGENT_WORKSPACE=' . sys_get_temp_dir());
        $kernel = new AgentKernel(\PhpAgent\Config::load(sys_get_temp_dir()));
        $fake = new FakeLLMClient([]);
        (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))
            ->register($kernel);

        self::assertSame('compact', (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))->name());
        self::assertSame('1.0.0', (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))->version());
        self::assertNotSame([], $kernel->hooks());
        self::assertArrayHasKey('compact', $kernel->commands());
    }

    public function testCompactCommandCompactsInPlace(): void
    {
        putenv('AGENT_WORKSPACE=' . sys_get_temp_dir());
        $kernel = new AgentKernel(\PhpAgent\Config::load(sys_get_temp_dir()));
        $fake = new FakeLLMClient([[StreamEvent::text('摘要内容。'), StreamEvent::end('end_turn')]]);
        (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))
            ->register($kernel);

        $sessionId = 's-test';
        $messages = $this->bigHistory();
        $ctx = new \PhpAgent\Command\CommandContext($kernel, $messages, $sessionId);
        $cmd = $kernel->command('compact');
        self::assertNotNull($cmd);

        $out = $cmd->execute([], $ctx);
        self::assertStringContainsString('已将前', $out);
        self::assertCount(3, $messages); // 合成对 + 当前任务，写穿生效
    }

    public function testCompactCommandNoopForShortHistory(): void
    {
        putenv('AGENT_WORKSPACE=' . sys_get_temp_dir());
        $kernel = new AgentKernel(\PhpAgent\Config::load(sys_get_temp_dir()));
        $fake = new FakeLLMClient([]);
        (new CompactPlugin(static fn (): Compactor => new Compactor($fake, 128000, 0.8), 128000, 0.8))
            ->register($kernel);

        $sessionId = 's-test';
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '唯一任务']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成']]],
        ];
        $ctx = new \PhpAgent\Command\CommandContext($kernel, $messages, $sessionId);
        $out = $kernel->command('compact')->execute([], $ctx);
        self::assertStringContainsString('无需压缩', $out);
        self::assertCount(2, $messages);
    }

    // ── 跟随分支（未显式指定压缩 provider）：contextTokens 压缩时刻经 registry 求值 ──

    protected function tearDown(): void
    {
        // 清理 followModeKernel 设置的环境变量，避免泄漏到同进程的其他测试
        putenv('AGENT_PROVIDER');
        putenv('AGENT_COMPACT_PROVIDER');
        putenv('AGENT_CONTEXT_TOKENS');
    }

    /**
     * 跟随分支真实注册路径：无注入工厂，插件自行装配（生产装配形态）。
     * AGENT_PROVIDER=fake 供 provider 回落；AGENT_COMPACT_PROVIDER 置空确保走跟随分支。
     * 注意插件注册先于 registry 挂载（bin/server 时序），工厂必须把 registry 查找推迟到压缩时刻。
     * $wrapClient 可选：包一层装饰 client（如记录摘要请求调用），验证压缩请求流经的 client 行为。
     */
    private function followModeKernel(int $globalContextTokens, ?\Closure $wrapClient = null): AgentKernel
    {
        putenv('AGENT_WORKSPACE=' . sys_get_temp_dir());
        putenv('AGENT_PROVIDER=fake');
        putenv('AGENT_COMPACT_PROVIDER=');
        putenv("AGENT_CONTEXT_TOKENS={$globalContextTokens}");
        $kernel = new AgentKernel(\PhpAgent\Config::load(sys_get_temp_dir()));
        $kernel->registerProvider('fake', static function () use ($wrapClient): \PhpAgent\LLM\LLMClient {
            $fake = new FakeLLMClient([[StreamEvent::text('早期完成了若干任务。'), StreamEvent::end('end_turn')]]);
            return $wrapClient !== null ? $wrapClient($fake) : $fake;
        });
        (new CompactPlugin())->register($kernel);
        return $kernel;
    }

    private function fireBeforeLlm(AgentKernel $kernel, array &$messages, array &$notes): void
    {
        $payload = ['messages' => &$messages, 'notes' => &$notes, 'system' => 'sys', 'iteration' => 1];
        HookPipeline::fire($kernel->hooks(), 'before_llm_request', $payload);
    }

    public function testFollowModeCompactsAtPerModelContextTokens(): void
    {
        // 模型条目 contextTokens=500 → 压缩时刻经 registry 求值；注册时全局值 1000000 仅是回落候选
        $kernel = $this->followModeKernel(1_000_000);
        $registry = new ProfileRegistry($kernel);
        $kernel->setProfileRegistry($registry); // 镜像 bin/server：插件注册之后才挂载 registry
        $registry->setProfiles([
            ['id' => 'p1', 'name' => '平台一', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '',
                'models' => [['name' => 'm1', 'contextTokens' => 500]]],
        ], 'p1');
        $registry->setActiveClient($registry->resolve('p1', 'm1')); // 镜像 AgentServer per-turn 接线
        self::assertSame(500, $registry->activeContextTokens());

        $messages = $this->bigHistory();
        $notes = [];
        $this->fireBeforeLlm($kernel, $messages, $notes);

        // per-model 500 × 0.8 = 400 触发压缩；若误用全局 1000000（阈值 800000）则不会压缩
        self::assertCount(3, $messages);
        self::assertStringContainsString('<context-summary>', $messages[0]['content'][0]['text']);
        self::assertCount(1, $notes);
    }

    public function testFollowModeFallsBackToGlobalContextTokensWhenNothingResolved(): void
    {
        // registry 已挂载但从未 resolve → activeContextTokens() 为 null → 回落注册时全局值
        $kernel = $this->followModeKernel(600);
        $kernel->setProfileRegistry(new ProfileRegistry($kernel));

        $messages = $this->bigHistory();
        $notes = [];
        $this->fireBeforeLlm($kernel, $messages, $notes);

        // 全局 600 × 0.8 = 480 ≤ 估算（约 1300）→ 压缩发生，证明全局值（而非默认 128000）流入 Compactor
        self::assertCount(3, $messages);
        self::assertCount(1, $notes);
    }

    public function testFollowModeWithoutRegistryFallsBackToGlobalContextTokens(): void
    {
        // 未挂载 registry（profileRegistry() 为 null 的 ?-> 路径）→ 同样回落全局值
        $kernel = $this->followModeKernel(600);

        $messages = $this->bigHistory();
        $notes = [];
        $this->fireBeforeLlm($kernel, $messages, $notes);

        self::assertCount(3, $messages);
        self::assertCount(1, $notes);
    }

    public function testFollowModeCompactionFollowsModelSwitchAcrossEvents(): void
    {
        // 事件 1（未达阈值，工厂被调用但不压缩）后 per-turn 切换模型 → 事件 2 即用新条目压缩。
        // 修复前 CompactHook 以 ??= 记忆化工厂结果：事件 2 仍用事件 1 冻结的 contextTokens（全局 1000000）→ 不压缩。
        $calls = 0;
        $onStream = static function () use (&$calls): void { $calls++; };
        $kernel = $this->followModeKernel(1_000_000, static function (\PhpAgent\LLM\LLMClient $inner) use ($onStream): \PhpAgent\LLM\LLMClient {
            // 装饰 client：记录摘要请求调用（registry 的 fake 分支解析到 kernel 同一 provider 实例，
            // 两条目共享实例不可区分，故以「调用日志」区分 client 是否在切换后才被首次使用）
            return new class($inner, $onStream) implements \PhpAgent\LLM\LLMClient {
                public function __construct(
                    private readonly \PhpAgent\LLM\LLMClient $inner,
                    private readonly \Closure $onStream,
                ) {
                }

                public function stream(array $messages, array $tools, string $system): \Generator
                {
                    ($this->onStream)();
                    yield from $this->inner->stream($messages, $tools, $system);
                }
            };
        });
        $registry = new ProfileRegistry($kernel);
        $kernel->setProfileRegistry($registry);
        $registry->setProfiles([
            ['id' => 'p1', 'name' => '平台一', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '',
                'models' => [['name' => 'm1'], ['name' => 'm2', 'contextTokens' => 500]]],
        ], 'p1');
        $registry->setActiveClient($registry->resolve('p1', 'm1')); // 事件 1 生效：m1 无条目参数 → 回落全局 1000000

        $messages = $this->bigHistory();
        $notes = [];
        $this->fireBeforeLlm($kernel, $messages, $notes);
        self::assertCount(9, $messages, '全局 1000000 阈值下事件 1 不压缩');
        self::assertSame([], $notes);
        self::assertSame(0, $calls, '未压缩则摘要请求不发出');

        // per-turn 切换：resolve 另一条目（contextTokens=500）+ setActiveClient（镜像 AgentServer 接线）
        $m2Client = $registry->resolve('p1', 'm2');
        $registry->setActiveClient($m2Client);
        self::assertSame(500, $registry->activeContextTokens());

        $this->fireBeforeLlm($kernel, $messages, $notes);
        // 事件 2 重新求值 → 500×0.8=400 ≤ 估算 1407 触发压缩；阈值跟随新条目即证明工厂每事件重新解析
        self::assertCount(3, $messages);
        self::assertStringContainsString('<context-summary>', $messages[0]['content'][0]['text']);
        self::assertCount(1, $notes);
        self::assertSame(1, $calls, '摘要请求恰一次，且发生在切换之后');
        self::assertSame($m2Client, $registry->activeClient(), '生效 client 已切换为新条目解析结果');
    }
}
