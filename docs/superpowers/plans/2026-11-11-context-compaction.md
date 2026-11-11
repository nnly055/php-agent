# 上下文压缩（compact 插件）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 会话历史超过阈值时自动用 LLM 把早期历史压缩为摘要（保留当前任务原文），并提供 `/compact` 手动命令；以独立插件实现，同时给核心加一条通用的「钩子→UI 通知」通道。

**Architecture:** `plugins/compact/` 插件挂 `before_llm_request` 钩子（引用携带 messages，估算超限则就地替换历史）+ `/compact` 命令（经 `CommandContext` 引用就地压缩）。核心改动仅两处：`AgentLoop` payload 增加 `notes`/`system` 引用槽并把 notes 作为新 `notice` 流事件发出；`StreamEvent` 增加 `notice` 事件。存储层零改动。

**Tech Stack:** PHP ≥ 8.1（无框架）、PHPUnit、FakeLLMClient（离线测试）。

**Spec:** `docs/superpowers/specs/2026-11-11-context-compaction-design.md`

## Global Constraints

- PHP ≥ 8.1，`declare(strict_types=1)` 每个文件必有；零框架，仅 curl/ReactPHP 已有依赖
- 归一化消息格式（对齐 Anthropic 块结构）：user 文本 `['role'=>'user','content'=>[['type'=>'text','text'=>...]]]`；tool_use 在 assistant 块；tool_result 在紧随的 user 块
- Anthropic 协议约束：user/assistant 严格交替、首条为 user、tool_use 必须有配对 tool_result
- 注释风格：中文、说明"为什么"；类 docblock 说明职责
- 插件类不经 composer autoload，由 `plugin.php` require，测试中显式 require_once（参照 `tests/DiffPluginTest.php:17-20`）
- 每个任务结束 `vendor/bin/phpunit` 全绿后再 commit
- 硬性默认值：`AGENT_CONTEXT_TOKENS=128000`、`AGENT_COMPACT_THRESHOLD=0.8`、token 估算除数 2.5

---

### Task 1: 钩子→UI 通知通道（notice 流事件）

**Files:**
- Modify: `src/LLM/StreamEvent.php`（docblock + notice 工厂）
- Modify: `src/Agent/AgentLoop.php:37-45`（notes/system 引用槽 + fire 后 emit）
- Modify: `src/Ui/ConsoleUi.php:121-131`（notice case）
- Modify: `public/index.html`（CSS + notice case）
- Test: `tests/AgentLoopTest.php`（新增测试方法）

**Interfaces:**
- Consumes: 现有 `HookPipeline::fire`、`StreamEvent` 私有构造 + 工厂模式
- Produces: `StreamEvent::notice(string $text): self`（type=`notice`，data=`{text:string}`）；`before_llm_request` payload 形态变为 `['messages'=>&$messages, 'notes'=>&$notes, 'system'=>string, 'iteration'=>int]`——Task 4 的 CompactHook 依赖这三个引用/键

- [ ] **Step 1: 写失败测试（AgentLoopTest 末尾新增方法）**

```php
    public function testHookNotesAreEmittedAsNoticeEvents(): void
    {
        // 钩子向 payload['notes']（引用槽）追加文本 → 循环应产出 notice 事件
        $hook = new class implements \PhpAgent\Hook\AgentHook {
            public function events(): array
            {
                return ['before_llm_request'];
            }

            public function handle(string $event, array $payload): void
            {
                $payload['notes'][] = '测试通知';
            }
        };

        $fake = new FakeLLMClient([[StreamEvent::text('ok'), StreamEvent::end('end_turn')]]);
        $loop = new AgentLoop($fake, new ToolRegistry(), 'test-system', 5, [$hook]);

        $messages = [];
        $events = [];
        $loop->run($messages, 'hi', static function (StreamEvent $e) use (&$events): void {
            $events[] = $e;
        });

        $notices = array_values(array_filter(
            $events,
            static fn (StreamEvent $e) => $e->type === 'notice'
        ));
        self::assertCount(1, $notices);
        self::assertSame('测试通知', $notices[0]->data['text']);
    }
```

同时在文件顶部 `use` 区补 `use PhpAgent\Hook\AgentHook;`（匿名类处也可用全限定名，二选一，上面代码用了全限定名则跳过）。

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/AgentLoopTest.php --filter testHookNotes`
Expected: FAIL（assertCount(1) 得到 0——notice 尚不存在）

- [ ] **Step 3: 实现 StreamEvent::notice + AgentLoop notes 槽**

`src/LLM/StreamEvent.php`：类 docblock 事件清单补一行 `notice            {text}         钩子向 UI 的通知（如上下文压缩）`，并加工厂方法（放在 `error()` 之后）：

```php
    /** 钩子向 UI 的通知（如上下文压缩、审计提示） */
    public static function notice(string $text): self
    {
        return new self('notice', ['text' => $text]);
    }
```

`src/Agent/AgentLoop.php` 37-45 行，把：

```php
        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            // 钩子点：before_llm_request —— payload 以引用携带 messages，可做上下文裁剪/注入
            $hookPayload = ['messages' => &$messages, 'iteration' => $iteration];
            try {
                HookPipeline::fire($this->hooks, 'before_llm_request', $hookPayload);
            } catch (HookVetoException $e) {
                throw new LLMException('本次 LLM 请求被钩子否决: ' . $e->getMessage());
            }
            unset($hookPayload);
```

改为：

```php
        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            // 钩子点：before_llm_request —— payload 以引用携带 messages（可做上下文裁剪/注入）
            // 与 notes（钩子→UI 通知通道，如压缩提示）；fire 是值传参，但引用元素写穿
            $notes = [];
            $hookPayload = [
                'messages' => &$messages,
                'notes' => &$notes,
                'system' => $this->system,
                'iteration' => $iteration,
            ];
            try {
                HookPipeline::fire($this->hooks, 'before_llm_request', $hookPayload);
            } catch (HookVetoException $e) {
                throw new LLMException('本次 LLM 请求被钩子否决: ' . $e->getMessage());
            }
            foreach ($notes as $note) {
                $emit(StreamEvent::notice((string) $note));
            }
            unset($hookPayload, $notes);
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/AgentLoopTest.php`
Expected: 全部 PASS（含原有用例——旧钩子不写 notes 时行为不变）

- [ ] **Step 5: ConsoleUi 与 Web 前端展示 notice**

`src/Ui/ConsoleUi.php` 在 `case 'message_end':` 之前插入：

```php
            case 'notice':
                $this->resetTextState();
                echo "\n  " . self::CYAN . 'ℹ ' . $ev->data['text'] . self::RESET;
                fflush(STDOUT);
                break;
```

`public/index.html`：CSS 区（`.msg.error { color:var(--red); }` 之后）加：

```css
  .msg.notice { color:var(--accent); border:1px dashed var(--border); align-self:flex-start; }
```

事件 switch（`case 'error':` 之前）加：

```js
            case 'notice': {
              const el = document.createElement('div');
              el.className = 'msg notice';
              el.textContent = 'ℹ ' + d.text;
              chat.appendChild(el); scroll(); break;
            }
```

- [ ] **Step 6: 全量测试 + 提交**

Run: `vendor/bin/phpunit`
Expected: 全部 PASS

```bash
git add src/LLM/StreamEvent.php src/Agent/AgentLoop.php src/Ui/ConsoleUi.php public/index.html tests/AgentLoopTest.php
git commit -m "feat: 钩子→UI 通知通道——before_llm_request notes 引用槽 + notice 流事件"
```

---

### Task 2: 压缩配置项（Config + .env.example）

**Files:**
- Modify: `src/Config.php`（新增 3 个访问器，放在 `auditDir()` 之后、类结束 `}` 之前）
- Modify: `.env.example`（会话持久化段之后追加压缩段）
- Test: `tests/ConfigTest.php`（新建）

**Interfaces:**
- Consumes: 现有 `$this->env` 数组（`.env` 优先、shell 兜底，值均为 string）
- Produces: `Config::contextTokens(): int`（默认 128000）、`Config::compactThreshold(): float`（默认 0.8，越界回落）、`Config::compactProvider(): string`（默认 `''`）——Task 3/4 的构造参数来源

- [ ] **Step 1: 写失败测试**

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/php-agent-config-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
    }

    private function configWith(string $envContent): Config
    {
        file_put_contents($this->dir . '/.env', $envContent);
        return Config::load($this->dir);
    }

    public function testCompactionDefaults(): void
    {
        $c = $this->configWith("AGENT_PROVIDER=openai\n");
        self::assertSame(128000, $c->contextTokens());
        self::assertSame(0.8, $c->compactThreshold());
        self::assertSame('', $c->compactProvider());
    }

    public function testCompactionOverrides(): void
    {
        $c = $this->configWith(
            "AGENT_CONTEXT_TOKENS=32000\nAGENT_COMPACT_THRESHOLD=0.5\nAGENT_COMPACT_PROVIDER=fake\n"
        );
        self::assertSame(32000, $c->contextTokens());
        self::assertSame(0.5, $c->compactThreshold());
        self::assertSame('fake', $c->compactProvider());
    }

    public function testCompactionInvalidFallsBackToDefaults(): void
    {
        $c = $this->configWith("AGENT_CONTEXT_TOKENS=abc\nAGENT_COMPACT_THRESHOLD=1.5\n");
        self::assertSame(128000, $c->contextTokens());
        self::assertSame(0.8, $c->compactThreshold());
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/ConfigTest.php`
Expected: FAIL（`Call to undefined method Config::contextTokens()`）

- [ ] **Step 3: 实现三个访问器**

`src/Config.php` 在 `auditDir()` 方法之后追加：

```php
    // ── 上下文压缩（compact 插件）────────────────────────────

    /** 模型上下文窗口 token 数（自动压缩阈值基数），按所用模型在 .env 调整 */
    public function contextTokens(): int
    {
        $v = filter_var($this->env['AGENT_CONTEXT_TOKENS'] ?? null, FILTER_VALIDATE_INT);
        return ($v !== false && $v > 0) ? $v : 128000;
    }

    /** 自动压缩阈值：估算 token 达到窗口该比例时触发（0 < t ≤ 1，越界回落默认） */
    public function compactThreshold(): float
    {
        $v = filter_var($this->env['AGENT_COMPACT_THRESHOLD'] ?? null, FILTER_VALIDATE_FLOAT);
        return ($v !== false && $v > 0 && $v <= 1) ? (float) $v : 0.8;
    }

    /** 压缩摘要请求所用 provider id，空 = AGENT_PROVIDER；--fake 离线演示设为 fake */
    public function compactProvider(): string
    {
        return trim($this->env['AGENT_COMPACT_PROVIDER'] ?? '');
    }
```

`.env.example` 在会话持久化段之后追加：

```bash

# ── 上下文压缩（compact 插件）─────────────────────────────
# 历史估算 token 达到「窗口 × 阈值」时自动用 LLM 压缩早期历史；/compact 手动触发
AGENT_CONTEXT_TOKENS=128000     # 模型上下文窗口（GLM-4.6/DeepSeek 128K，按模型调整）
AGENT_COMPACT_THRESHOLD=0.8     # 0-1
# AGENT_COMPACT_PROVIDER=       # 压缩用 provider，空 = AGENT_PROVIDER；离线演示设 fake
```

- [ ] **Step 4: 运行确认通过 + 全量**

Run: `vendor/bin/phpunit tests/ConfigTest.php && vendor/bin/phpunit`
Expected: 全部 PASS

- [ ] **Step 5: 提交**

```bash
git add src/Config.php .env.example tests/ConfigTest.php
git commit -m "feat: 压缩配置——AGENT_CONTEXT_TOKENS/AGENT_COMPACT_THRESHOLD/AGENT_COMPACT_PROVIDER"
```

---

### Task 3: Compactor 核心算法

**Files:**
- Create: `plugins/compact/src/CompactionResult.php`
- Create: `plugins/compact/src/Compactor.php`
- Test: `tests/CompactorTest.php`（新建，测试中显式 require 两个类）

**Interfaces:**
- Consumes: `PhpAgent\LLM\LLMClient`（`stream(array $messages, array $tools, string $system): \Generator`）、`StreamEvent::text()`、`LLMException`
- Produces（Task 4/5 依赖，签名精确如下）:
  - `new Compactor(LLMClient $client, int $contextTokens = 128000, float $threshold = 0.8)`
  - `Compactor::estimateTokens(array $messages, string $system = ''): int`（static）
  - `Compactor::findSplit(array $messages): int`（static，0=无可压缩）
  - `Compactor::isTaskStart(array $message): bool`（static）
  - `Compactor::shouldCompact(array $messages, string $system): bool`
  - `Compactor::compact(array &$messages, string $system): CompactionResult`（引用就地替换）
  - `Compactor::SUMMARY_SYSTEM: string`（public const）
  - `new CompactionResult(bool $compacted, string $note, int $removedMessages, int $beforeTokens, int $afterTokens)`，公开 readonly 属性

- [ ] **Step 1: 写 CompactionResult**

`plugins/compact/src/CompactionResult.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

/**
 * 一次压缩的结果：compact() 的返回值，钩子与命令共用。
 * note 同时承载成功提示与失败原因；未尝试压缩（无可压缩边界）时 note 为空串。
 */
final class CompactionResult
{
    public function __construct(
        public readonly bool $compacted,
        public readonly string $note,
        public readonly int $removedMessages,
        public readonly int $beforeTokens,
        public readonly int $afterTokens,
    ) {
    }
}
```

- [ ] **Step 2: 写失败测试**

`tests/CompactorTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Plugin\Compact\CompactionResult;
use PhpAgent\Plugin\Compact\Compactor;
use PHPUnit\Framework\TestCase;

// 插件类不经 composer autoload（项目插件由 plugin.php require），测试中显式引入
require_once dirname(__DIR__) . '/plugins/compact/src/CompactionResult.php';
require_once dirname(__DIR__) . '/plugins/compact/src/Compactor.php';

/**
 * Compactor 核心算法：token 估算、压缩边界、摘要替换、失败语义。
 * 全部离线：摘要请求走 FakeLLMClient / 抛异常的桩客户端。
 */
final class CompactorTest extends TestCase
{
    /** 多任务历史：任务一(2条) + 任务二含工具配对(4条)，findSplit 应= 2 */
    private function taskHistory(): array
    {
        return [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '任务一：创建 a.php']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '好的，已创建 a.php']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '任务二：创建 b.php 并运行']]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'write_file', 'input' => ['path' => 'b.php']],
            ]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'OK']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成，b.php 已创建并运行']]],
        ];
    }

    public function testEstimateTokensMatchesFormula(): void
    {
        // json: [{"role":"user","content":[{"type":"text","text":"abcd"}]}] = 59 字符 → ceil(59/2.5) = 24
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'abcd']]]];
        self::assertSame(24, Compactor::estimateTokens($messages));
        self::assertSame(0, Compactor::estimateTokens([], ''));
    }

    public function testEstimateTokensGrowsWithHistory(): void
    {
        $short = $this->taskHistory();
        $long = array_merge($short, $short);
        self::assertGreaterThan(
            Compactor::estimateTokens($short, 'sys'),
            Compactor::estimateTokens($long, 'sys')
        );
    }

    public function testFindSplitAtLastTaskStart(): void
    {
        self::assertSame(2, Compactor::findSplit($this->taskHistory()));
    }

    public function testFindSplitZeroForSingleTask(): void
    {
        $one = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '唯一任务']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成']]],
        ];
        self::assertSame(0, Compactor::findSplit($one));
        self::assertSame(0, Compactor::findSplit([]));
    }

    public function testIsTaskStartExcludesToolResultMessages(): void
    {
        $toolResultMsg = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 't1', 'content' => 'OK']]];
        self::assertFalse(Compactor::isTaskStart($toolResultMsg));
        self::assertTrue(Compactor::isTaskStart($this->taskHistory()[2]));
    }

    public function testShouldCompactRespectsThreshold(): void
    {
        $history = $this->taskHistory();
        $tokens = Compactor::estimateTokens($history, 'sys');

        $hot = new Compactor(new FakeLLMClient([]), $tokens, 0.8);      // 阈值=估算值本身 → 触发
        $cold = new Compactor(new FakeLLMClient([]), $tokens * 10, 0.8); // 窗口富余 → 不触发
        self::assertTrue($hot->shouldCompact($history, 'sys'));
        self::assertFalse($cold->shouldCompact($history, 'sys'));
    }

    public function testCompactReplacesWithSummaryPairAndKeepsCurrentTask(): void
    {
        $fake = new FakeLLMClient([
            [StreamEvent::text('- 任务一：创建 a.php（已完成）'), StreamEvent::end('end_turn')],
        ]);
        $compactor = new Compactor($fake, 128000, 0.8);

        $messages = $this->taskHistory();
        $result = $compactor->compact($messages, 'sys');

        // 结果形态：合成对(2条) + 保留当前任务(4条) = 6 条
        self::assertTrue($result->compacted);
        self::assertSame(2, $result->removedMessages);
        self::assertCount(6, $messages);

        // 摘要 user 消息 + assistant 确认，恢复 user/assistant 交替
        self::assertSame('user', $messages[0]['role']);
        self::assertStringContainsString('<context-summary>', $messages[0]['content'][0]['text']);
        self::assertStringContainsString('任务一', $messages[0]['content'][0]['text']);
        self::assertSame('assistant', $messages[1]['role']);

        // 保留段原样：任务二起点 + tool_use/tool_result 配对完整
        self::assertSame('任务二：创建 b.php 并运行', $messages[2]['content'][0]['text']);
        self::assertSame('tool_use', $messages[3]['content'][0]['type']);
        self::assertSame('t1', $messages[4]['content'][0]['tool_use_id']);

        // 提示文本可读
        self::assertStringContainsString('已将前 2 条消息压缩为摘要', $result->note);
    }

    public function testCompactNoopWhenSingleTask(): void
    {
        $compactor = new Compactor(new FakeLLMClient([]), 128000, 0.8);
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => '唯一任务']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '完成']]],
        ];
        $result = $compactor->compact($messages, '');
        self::assertFalse($result->compacted);
        self::assertSame('', $result->note);
        self::assertCount(2, $messages); // 原历史不动
    }

    public function testCompactFailureKeepsHistoryIntact(): void
    {
        $boom = new class implements \PhpAgent\LLM\LLMClient {
            public function stream(array $messages, array $tools, string $system): \Generator
            {
                throw new \RuntimeException('网络断了');
                yield; // 生成器语法占位
            }
        };
        $compactor = new Compactor($boom, 128000, 0.8);

        $messages = $this->taskHistory();
        $snapshot = $messages;
        $result = $compactor->compact($messages, '');

        self::assertFalse($result->compacted);
        self::assertStringContainsString('压缩失败', $result->note);
        self::assertStringContainsString('网络断了', $result->note);
        self::assertSame($snapshot, $messages); // 历史原样保留
    }

    public function testCompactEmptySummaryIsFailure(): void
    {
        // FakeLLM 脚本播完后的兜底文本会返回内容，这里用只 yield 非文本事件的客户端
        $silent = new class implements \PhpAgent\LLM\LLMClient {
            public function stream(array $messages, array $tools, string $system): \Generator
            {
                yield StreamEvent::end('end_turn');
            }
        };
        $compactor = new Compactor($silent, 128000, 0.8);
        $messages = $this->taskHistory();
        $result = $compactor->compact($messages, '');
        self::assertFalse($result->compacted);
        self::assertStringContainsString('压缩失败', $result->note);
        self::assertCount(6, $messages);
    }
}
```

- [ ] **Step 3: 运行确认失败**

Run: `vendor/bin/phpunit tests/CompactorTest.php`
Expected: FAIL（`Failed opening required .../Compactor.php`）

- [ ] **Step 4: 实现 Compactor**

`plugins/compact/src/Compactor.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\LLM\LLMClient;
use PhpAgent\LLM\LLMException;

/**
 * 上下文压缩核心算法：token 估算（保守启发式）、压缩边界选择、
 * 调 LLM 生成摘要并就地替换历史。无 tokenizer，估算宁可偏大（早压缩）。
 *
 * 边界规则：归一化历史中「普通 user 文本消息」= 任务起点（tool_result 消息
 * 虽为 user 角色但被排除）。压缩 [0, splitIndex)，当前任务全程原样保留——
 * 天然不切开 tool_use↔tool_result 配对，满足 Anthropic 交替约束。
 *
 * 替换形态：[user: 摘要] + [assistant: 短确认] + 保留段。
 * 摘要若为单独 user 消息会与保留段开头的 user 连续两条，违反交替约束，
 * 故补一条 assistant 确认恢复交替。
 */
final class Compactor
{
    /** 摘要请求的 system 提示词（与 agent 主循环的 system 无关） */
    public const SUMMARY_SYSTEM = <<<'TXT'
你是对话历史压缩器。把给定的早期对话历史压缩成供后续对话使用的摘要：
1. 保留：任务目标、关键决定与理由、涉及的文件路径与改动内容、未完成事项、用户提出的约束与偏好。
2. 舍弃：中间过程细节、完整工具输出、重复内容。
3. 用中文输出纯文本，按「任务目标 / 关键决定 / 文件改动 / 未完成事项 / 约束偏好」分节。
TXT;

    private const TOKEN_DIVISOR = 2.5; // 字符→token 保守估算（中英混合，宁大勿小）

    public function __construct(
        private readonly LLMClient $client,
        private readonly int $contextTokens = 128000,
        private readonly float $threshold = 0.8,
    ) {
    }

    /** 保守估算：JSON 字符数 + system 长度，除以固定除数向上取整 */
    public static function estimateTokens(array $messages, string $system = ''): int
    {
        $chars = mb_strlen(json_encode($messages, JSON_UNESCAPED_UNICODE) ?: '')
            + mb_strlen($system);
        return (int) ceil($chars / self::TOKEN_DIVISOR);
    }

    /** 估算达到「窗口 × 阈值」即触发 */
    public function shouldCompact(array $messages, string $system): bool
    {
        return self::estimateTokens($messages, $system)
            >= (int) round($this->contextTokens * $this->threshold);
    }

    /** 最后一个任务起点下标；0 = 没有可压缩的历史 */
    public static function findSplit(array $messages): int
    {
        $split = 0;
        foreach ($messages as $i => $m) {
            if (is_array($m) && self::isTaskStart($m)) {
                $split = (int) $i;
            }
        }
        return $split;
    }

    /** 普通 user 文本消息 = 任务起点；tool_result 消息被排除 */
    public static function isTaskStart(array $message): bool
    {
        if (($message['role'] ?? '') !== 'user') {
            return false;
        }
        $content = $message['content'] ?? [];
        if (!is_array($content) || $content === []) {
            return false;
        }
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_result') {
                return false;
            }
        }
        return true;
    }

    /**
     * 就地压缩：[0, splitIndex) 换成摘要合成对，其余原样保留。
     * 失败（LLM 异常/空摘要）绝不修改历史，原因写入 note。
     */
    public function compact(array &$messages, string $system): CompactionResult
    {
        $split = self::findSplit($messages);
        if ($split === 0) {
            return new CompactionResult(false, '', 0, 0, 0);
        }
        $before = self::estimateTokens($messages, $system);
        $old = array_slice($messages, 0, $split);

        try {
            $summary = $this->summarize($old);
        } catch (\Throwable $e) {
            return new CompactionResult(
                false,
                '〔上下文压缩〕压缩失败，已保留原历史：' . $e->getMessage(),
                0,
                $before,
                $before,
            );
        }

        $summaryMsg = [
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => "<context-summary>\n{$summary}\n</context-summary>\n\n"
                    . '以上是早期对话的压缩摘要，请基于它继续当前任务。',
            ]],
        ];
        $ackMsg = [
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => '已了解上述摘要，继续处理当前任务。']],
        ];
        $messages = array_merge([$summaryMsg, $ackMsg], array_slice($messages, $split));
        $after = self::estimateTokens($messages, $system);

        return new CompactionResult(
            true,
            sprintf(
                '〔上下文压缩〕已将前 %d 条消息压缩为摘要（估算 %d → %d tokens）',
                $split,
                $before,
                $after,
            ),
            $split,
            $before,
            $after,
        );
    }

    /** 发起无工具总结请求，聚合 text_delta；失败抛异常（compact 捕获） */
    private function summarize(array $old): string
    {
        $request = [[
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => "以下是需要压缩的早期对话历史：\n\n<history>\n"
                    . self::serializeHistory($old)
                    . "\n</history>",
            ]],
        ]];
        $text = '';
        foreach ($this->client->stream($request, [], self::SUMMARY_SYSTEM) as $ev) {
            if ($ev->type === 'text_delta') {
                $text .= $ev->data['text'];
            }
        }
        $summary = trim($text);
        if ($summary === '') {
            throw new LLMException('摘要请求未返回文本');
        }
        return $summary;
    }

    /** 历史序列化为摘要请求文本：文本全量、tool_use 参数全量、tool_result 截断到 2000 字符 */
    private static function serializeHistory(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? '?';
            foreach (($m['content'] ?? []) as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'text') {
                    $lines[] = "[{$role}] " . (string) ($block['text'] ?? '');
                } elseif ($type === 'tool_use') {
                    $json = json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}';
                    $lines[] = "[{$role}] [调用工具 " . ($block['name'] ?? '?') . " 参数 {$json}]";
                } elseif ($type === 'tool_result') {
                    $out = mb_substr((string) ($block['content'] ?? ''), 0, 2000);
                    $lines[] = "[{$role}] [工具结果 {$out}]";
                }
            }
        }
        return implode("\n", $lines);
    }
}
```

注意：两份 create 的文件由 Task 4 的 `plugin.php` 统一 require，本任务不需要 bootstrap。

- [ ] **Step 5: 运行确认通过 + 全量**

Run: `vendor/bin/phpunit tests/CompactorTest.php && vendor/bin/phpunit`
Expected: 全部 PASS

- [ ] **Step 6: 提交**

```bash
git add plugins/compact/src/CompactionResult.php plugins/compact/src/Compactor.php tests/CompactorTest.php
git commit -m "feat: Compactor 核心算法——token 估算/任务起点边界/LLM 摘要就地替换"
```

---

### Task 4: CompactHook + CompactPlugin（自动压缩 + 插件引导）

**Files:**
- Create: `plugins/compact/src/CompactHook.php`
- Create: `plugins/compact/src/CompactPlugin.php`
- Create: `plugins/compact/plugin.php`
- Test: `tests/CompactPluginTest.php`（新建）

**Interfaces:**
- Consumes: Task 1 的 payload 形态（`messages`/`notes` 引用槽 + `system` 键）、Task 2 的 `Config::contextTokens()/compactProvider()`、Task 3 的 Compactor 全部公开 API、`AgentKernel::hasProvider()/provider()`、`HookPipeline::fire`
- Produces: 插件名 `compact` v1.0.0；`CompactHook::__construct(\Closure $compactorFactory, int $contextTokens, float $threshold)`（工厂签名 `fn(): Compactor`）；`CompactPlugin::__construct(?\Closure $compactorFactory = null, ?int $contextTokens = null, ?float $threshold = null)`——null 时 register() 从 Config 解析（生产装配 `plugin.php` 无参实例化走 Config）；命令类 `CompactCommand` 本任务一并创建（Task 5 只补测试）

- [ ] **Step 1: 写失败测试**

`tests/CompactPluginTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Hook\HookPipeline;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Plugin\Compact\CompactPlugin;
use PhpAgent\Plugin\Compact\Compactor;
use PHPUnit\Framework\TestCase;

// 插件类不经 composer autoload（项目插件由 plugin.php require），测试中显式引入
require_once dirname(__DIR__) . '/plugins/compact/src/CompactionResult.php';
require_once dirname(__DIR__) . '/plugins/compact/src/Compactor.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactHook.php';
require_once dirname(__DIR__) . '/plugins/compact/src/CompactPlugin.php';

/**
 * compact 插件：钩子接线（估算→压缩→notes 写穿）、失败节流、provider 落选逻辑。
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
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/CompactPluginTest.php`
Expected: FAIL（`Failed opening required .../CompactHook.php`）

- [ ] **Step 3: 实现 CompactHook**

`plugins/compact/src/CompactHook.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\Hook\AgentHook;

/**
 * before_llm_request 钩子：估算超阈值则压缩早期历史。
 *
 * notes 引用槽写穿到 AgentLoop → notice 事件 → UI 可见。
 * 失败节流：一次压缩失败后，同任务内（尾条不是任务起点）不再重试，
 * 避免工具循环里每轮迭代重复发起注定失败的请求、notes 刷屏。
 */
final class CompactHook implements AgentHook
{
    private ?Compactor $compactor = null;
    private bool $failed = false;

    /** @param \Closure(): Compactor $compactorFactory 惰性构造（provider 运行期才解析） */
    public function __construct(
        private readonly \Closure $compactorFactory,
        private readonly int $contextTokens,
        private readonly float $threshold,
    ) {
    }

    public function events(): array
    {
        return ['before_llm_request'];
    }

    public function handle(string $event, array $payload): void
    {
        $messages = &$payload['messages'];
        if (!is_array($messages) || $messages === []) {
            return;
        }

        if ($this->failed) {
            $tail = end($messages);
            if (!is_array($tail) || !Compactor::isTaskStart($tail)) {
                return; // 同任务内节流
            }
            $this->failed = false; // 新任务起点，恢复尝试
        }

        $compactor = $this->compactor ??= ($this->compactorFactory)();
        $system = (string) ($payload['system'] ?? '');
        if (!$compactor->shouldCompact($messages, $system)) {
            return;
        }

        $result = $compactor->compact($messages, $system);
        if ($result->compacted) {
            $payload['notes'][] = $result->note;
        } elseif ($result->note !== '') {
            $this->failed = true;
            $payload['notes'][] = $result->note;
        }
    }
}
```

- [ ] **Step 4: 实现 CompactPlugin + plugin.php 引导**

`plugins/compact/src/CompactPlugin.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;

/**
 * compact 插件：上下文压缩——before_llm_request 自动阈值压缩 + /compact 手动命令。
 * 同时是「before_llm_request 钩子（引用裁剪）+ registerCommand」双扩展点的参考实现。
 *
 * provider 解析：AGENT_COMPACT_PROVIDER 优先（--fake 离线演示设 fake），
 * 空 = AGENT_PROVIDER。惰性构造：注册时不实例化客户端，首次压缩才解析。
 * 构造参数全部可空：生产装配（plugin.php 无参实例化）走 Config；
 * 测试经构造参数显式注入工厂与阈值。
 */
final class CompactPlugin implements Plugin
{
    public function __construct(
        private readonly ?\Closure $compactorFactory = null,
        private readonly ?int $contextTokens = null,
        private readonly ?float $threshold = null,
    ) {
    }

    public function name(): string
    {
        return 'compact';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        $config = $kernel->config();
        $contextTokens = $this->contextTokens ?? $config->contextTokens();
        $threshold = $this->threshold ?? $config->compactThreshold();

        if ($this->compactorFactory !== null) {
            $factory = $this->compactorFactory;
        } else {
            $providerId = $config->compactProvider();
            if ($providerId === '' || !$kernel->hasProvider($providerId)) {
                $providerId = $config->provider();
            }
            $factory = static fn (): Compactor => new Compactor(
                $kernel->provider($providerId),
                $contextTokens,
                $threshold,
            );
        }

        $kernel->addHook(new CompactHook($factory, $contextTokens, $threshold));
        $kernel->registerCommand('compact', new CompactCommand($factory));
    }
}
```

`CompactCommand`（Task 5 Step 1 有完整代码与注释）在本 Step 一并创建为
`plugins/compact/src/CompactCommand.php`（原样照抄 Task 5 Step 1 的类体），
Task 5 只做命令测试。

`plugins/compact/plugin.php`：

```php
<?php

// compact 插件：上下文压缩（before_llm_request 自动阈值 + /compact 手动命令）
// 禁用方式：重命名本文件为 plugin.php.disabled
require_once __DIR__ . '/src/CompactionResult.php';
require_once __DIR__ . '/src/Compactor.php';
require_once __DIR__ . '/src/CompactCommand.php';
require_once __DIR__ . '/src/CompactHook.php';
require_once __DIR__ . '/src/CompactPlugin.php';

return PhpAgent\Plugin\Compact\CompactPlugin::class;
```

- [ ] **Step 5: 语法检查 + 运行确认通过 + 全量**

Run: `php -l plugins/compact/plugin.php && php -l plugins/compact/src/CompactPlugin.php && php -l plugins/compact/src/CompactHook.php && php -l plugins/compact/src/CompactCommand.php && vendor/bin/phpunit tests/CompactPluginTest.php && vendor/bin/phpunit`
Expected: 语法 OK，测试全部 PASS

- [ ] **Step 6: 提交**

```bash
git add plugins/compact/plugin.php plugins/compact/src/CompactHook.php plugins/compact/src/CompactPlugin.php plugins/compact/src/CompactCommand.php tests/CompactPluginTest.php
git commit -m "feat: compact 插件——before_llm_request 自动压缩钩子 + 失败节流 + 插件引导"
```

---

### Task 5: /compact 手动命令（测试）

**Files:**
- Modify: `tests/CompactPluginTest.php`（追加命令测试；类文件已在 Task 4 按 b 方案创建）

**Interfaces:**
- Consumes: `CompactCommand::__construct(\Closure $compactorFactory)`（工厂签名 `fn(): Compactor`）、`CommandContext`（`public array &$messages` + `kernel()`）、Task 3 的 `Compactor::findSplit()/compact()`
- Produces: `/compact` 行为契约——splitIndex=0 →「当前历史较短，无需压缩」；否则压缩并返回 note

- [ ] **Step 1: （Task 4 已创建类文件，此处核对签名）**

`plugins/compact/src/CompactCommand.php`（Task 4 Step 4-b 已按此创建，核对一致即可）：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Plugin\Compact;

use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;

/**
 * /compact：手动压缩当前会话历史（忽略自动阈值，边界规则与自动压缩一致）。
 * 经 CommandContext 的 messages 引用就地替换，下一轮对话即用压缩后历史。
 */
final class CompactCommand implements Command
{
    /** @param \Closure(): Compactor $compactorFactory */
    public function __construct(private readonly \Closure $compactorFactory)
    {
    }

    public function description(): string
    {
        return '手动压缩当前会话历史（LLM 摘要，保留当前任务）';
    }

    public function execute(array $args, CommandContext $ctx): string
    {
        if (Compactor::findSplit($ctx->messages) === 0) {
            return '（当前历史较短，无需压缩）';
        }
        $result = ($this->compactorFactory)()->compact($ctx->messages, '');
        return $result->note !== '' ? $result->note : '（无可压缩内容）';
    }
}
```

- [ ] **Step 2: 追加失败测试（CompactPluginTest 末尾）**

```php
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
```

- [ ] **Step 3: 运行确认通过 + 全量**

Run: `vendor/bin/phpunit tests/CompactPluginTest.php && vendor/bin/phpunit`
Expected: 全部 PASS（类已在 Task 4 创建，测试应直接通过——若失败，按 systematic-debugging 修类）

- [ ] **Step 4: 提交**

```bash
git add tests/CompactPluginTest.php
git commit -m "test: /compact 手动压缩命令——就地压缩与短历史提示"
```

---

### Task 6: 端到端验证 + 文档

**Files:**
- Modify: `README.md`（「权限模式与审计」段之后加 compact 段）
- Modify: `docs/plugin-dev-guide.md`（钩子扩展点处标注 compact 为参考实现）
- Modify: `CHANGELOG.md`（Unreleased/新增 追加条目）

**Interfaces:**
- Consumes: 前五个任务的全部产出
- Produces: 无（文档）

- [ ] **Step 1: 离线端到端冒烟（--fake + AGENT_COMPACT_PROVIDER=fake）**

```bash
AGENT_COMPACT_PROVIDER=fake php bin/agent --fake
```

在交互里输入 `/compact`：
Expected: 输出「当前历史较短，无需压缩」（fake 空历史）或摘要提示；`/plugins` 列表出现 `compact v1.0.0`；`/help` 出现 `/compact`。无 PHP 报错/警告。

- [ ] **Step 2: README 增加 compact 小节**

在「权限模式与审计」小节之后插入：

```markdown
### 上下文压缩

会话历史增长到接近模型窗口时，`compact` 插件会在下一轮请求前自动把早期历史
用当前 LLM 压缩成一条摘要（保留当前任务原文，绝不切开工具调用配对），
CLI/Web 都会收到 ℹ 提示；`/compact` 可随时手动触发。

```bash
AGENT_CONTEXT_TOKENS=128000     # 模型上下文窗口（按所用模型调整）
AGENT_COMPACT_THRESHOLD=0.8     # 估算达到窗口 80% 时自动压缩
# AGENT_COMPACT_PROVIDER=       # 压缩用 provider，空 = AGENT_PROVIDER；离线演示设 fake
```

token 为保守估算（字符数 ÷ 2.5，无 tokenizer）；压缩就地修改历史，
持久化与 /resume 自动一致。停用：重命名 plugins/compact/plugin.php 为
plugin.php.disabled。该插件同时是 `before_llm_request` 钩子
（引用裁剪历史）+ `/compact` 命令双扩展点的参考实现。
```

- [ ] **Step 3: plugin-dev-guide 标注参考实现**

在钩子扩展点相关小节（搜索 `before_llm_request`）追加一句：

```markdown
参考实现：plugins/compact/（估算超阈值就地在钩子里裁剪历史，并通过
payload['notes'] 引用槽向 UI 发 notice 通知；失败节流见 CompactHook）。
```

- [ ] **Step 4: CHANGELOG Unreleased 新增条目**

在 `## [Unreleased]` 的 `### 新增` 列表最上方追加：

```markdown
- **上下文压缩（compact 插件）**
  - plugins/compact/：`Compactor`（保守 token 估算 + 任务起点边界 + LLM 摘要
    就地替换，保留当前任务原文、不切开工具配对）+ `CompactHook`
    （`before_llm_request` 自动阈值触发，失败节流到下一任务）+ `/compact` 手动命令
  - 核心：`before_llm_request` payload 增加 notes/system 引用槽，
    notes 经新增 `notice` 流事件直达 CLI/Web UI（钩子→UI 通知通用通道）
  - 配置：`AGENT_CONTEXT_TOKENS` / `AGENT_COMPACT_THRESHOLD` / `AGENT_COMPACT_PROVIDER`
  - 同时作为 `before_llm_request` + `registerCommand` 双扩展点的官方参考实现
```

- [ ] **Step 5: 全量测试 + 提交**

Run: `vendor/bin/phpunit`
Expected: 全部 PASS

```bash
git add README.md docs/plugin-dev-guide.md CHANGELOG.md
git commit -m "docs: 上下文压缩使用说明——compact 插件、配置项与扩展点参考实现标注"
```
