# 桌面壳 MVP 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把 php-agent 包成端到端可用的 Tauri 2 桌面应用：Rust 壳管理 PHP sidecar，独立 React/shadcn 前端直连 HTTP/SSE，补齐会话/授权确认 API。

**Architecture:** 方案 A——Tauri 壳 spawn `php bin/server` 子进程（dev 用系统 PHP），从 stdout 解析动态端口；React 前端经 `get_backend_url` 拿到基址后直连 sidecar 的 HTTP+SSE。授权确认复用 `ConfirmHook`，prompt 闭包换成 `ConfirmBroker`（SSE 发 `confirm_request` + `React\Async\await` 挂起 Fiber 等 `POST /confirm`）。

**Tech Stack:** PHP 8.1+/ReactPHP（后端）；Rust/Tauri 2（壳）；Vite+React 18+TS+Tailwind+shadcn/ui+Zustand（前端）；PHPUnit/Vitest（测试）。

**Spec:** `docs/superpowers/specs/2026-11-11-desktop-shell-design.md`

## Global Constraints

- PHP ≥ 8.1，需 curl/json/mbstring；**后端零新增运行时依赖**（ReactPHP react/async、react/http 已在 composer.json）。
- 现有 **101 个 PHPUnit 测试必须保持绿**；CLI 行为（`bin/agent`、DiffPreviewHook echo、ConfirmHook 双参 prompt）不得回归。
- server 仅绑 `127.0.0.1`；所有响应带 `Access-Control-Allow-Origin: *`；OPTIONS 预检返 204。
- 端口协商：`AGENT_SERVER_PORT=0` → 绑 0 → stdout 打机器可读行 `AGENT_LISTENING port=<port>`（格式逐字）。
- 确认超时 **120s** → 自动 `n`；MVP 假定**每会话同一时刻只有一条流**（前端流式期间禁用 Composer）。
- Tauri **2**；Rust stable；Node 22；Vite dev 端口 **1420**（strictPort）。
- 前端依赖克制：react-markdown、remark-gfm、lucide-react、zustand；shadcn 原语用 `npx shadcn@latest add` 生成（Radix 随之引入）。
- 视觉 token 逐字采用 spec 调色板（`--bg:#0B0D12` 等，见 Task 11）。
- workspace 持久化文件 `~/.php-agent/desktop.json`；`AGENT_UI=server` 由 `bin/server` 设置。
- 提交信息用中文 conventional commits（对齐项目历史，如 `feat: …`/`fix: …`/`docs: …`）。
- 所有新 PHP 文件 `declare(strict_types=1);`，命名空间遵循 PSR-4（`PhpAgent\` → src/，`PhpAgent\Tests\` → tests/）。

## 文件结构

**后端（修改/新增）**
```
src/Config.php                       # 修改：serverPort 放行 0 + VERSION 常量
src/LLM/StreamEvent.php              # 修改：confirmRequest 工厂
src/Server/ConfirmBroker.php         # 新增：HTTP 确认协调（pending/Deferred/超时/preview）
src/Server/AgentServer.php           # 修改：CORS + /status + sessions + /confirm + 接 broker
bin/server                           # 修改：端口 0 + AGENT_LISTENING + ConfirmHook 装配 + AGENT_UI
plugins/diff/src/DiffPreviewHook.php # 修改：可选 output 回调
plugins/diff/src/DiffPlugin.php      # 修改：按 AGENT_UI 选 echo/no-op
tests/ConfigTest.php                 # 修改：serverPort 0 + VERSION
tests/StreamEventTest.php            # 新增：confirmRequest 形状
tests/ConfirmBrokerTest.php          # 新增：确认流状态机
tests/ServerApiTest.php              # 新增：端点 + CORS
```

**壳 + 前端（新增 desktop/ 整树）**
```
desktop/package.json · vite.config.ts · tsconfig.json · tsconfig.node.json · index.html
desktop/src/index.css                # Tailwind + 主题 token + 自绘滚动条
desktop/src/main.tsx · App.tsx
desktop/src/lib/utils.ts · api.ts · sse.ts · tauri.ts · diff.ts
desktop/src/store/useAppStore.ts
desktop/src/hooks/useAgentStream.ts
desktop/src/components/layout/{AppShell,TitleBar,Sidebar,StatusBar}.tsx
desktop/src/components/chat/{ChatView,MessageBubble,Markdown,Composer,ToolCallCard,DiffView}.tsx
desktop/src/components/confirm/ConfirmDialog.tsx
desktop/src/components/sessions/{SessionList,SessionItem}.tsx
desktop/src/components/Welcome.tsx · ConnectionBanner.tsx
desktop/src/components/ui/*          # shadcn CLI 生成
desktop/src/lib/sse.test.ts · diff.test.ts · store.test.ts
desktop/src-tauri/Cargo.toml · tauri.conf.json · build.rs · capabilities/default.json
desktop/src-tauri/src/main.rs · lib.rs · sidecar.rs
```

---

## Phase 1 — 后端 API（PHPUnit TDD）

### Task 1: Config — serverPort 放行 0 + VERSION 常量

**Files:**
- Modify: `src/Config.php:100-103`（serverPort）+ 类顶部加常量
- Test: `tests/ConfigTest.php`

**Interfaces:**
- Produces: `Config::VERSION`（string `'0.2.0'`）；`Config::serverPort(): int`（0 = 自动）

- [ ] **Step 1: 写失败测试**（追加到 `tests/ConfigTest.php` 类内；复用现有 `configWith()` 辅助，它用 setUp 建的临时目录 + `Config::load`）

```php
public function testServerPortAllowsZeroForAutoAssign(): void
{
    self::assertSame(0, $this->configWith("AGENT_SERVER_PORT=0\n")->serverPort());
}

public function testServerPortDefaultsTo8091(): void
{
    self::assertSame(8091, $this->configWith("AGENT_PROVIDER=fake\n")->serverPort());
}

public function testVersionConstantExists(): void
{
    self::assertSame('0.2.0', Config::VERSION);
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit --filter testServerPortAllowsZeroForAutoAssign`
Expected: FAIL（当前 `max(1,…)` 把 0 钳成 1，断言 0 失败）

- [ ] **Step 3: 实现**

`src/Config.php` 类首行加常量：

```php
final class Config
{
    /** 应用版本（/status 展示用；发版手动 bump，单一来源） */
    public const VERSION = '0.2.0';

    private function __construct(private array $env)
    {
    }
```

serverPort 改为：

```php
    public function serverPort(): int
    {
        return max(0, (int) ($this->env['AGENT_SERVER_PORT'] ?? 8091));
    }
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit --filter 'ConfigTest'`
Expected: PASS（含新两条）

- [ ] **Step 5: 提交**

```bash
git add src/Config.php tests/ConfigTest.php
git commit -m "feat: Config 放行 serverPort=0 自动选端口 + VERSION 常量"
```

---

### Task 2: StreamEvent::confirmRequest 工厂

**Files:**
- Modify: `src/LLM/StreamEvent.php`
- Test: `tests/StreamEventTest.php`（新建）

**Interfaces:**
- Produces: `StreamEvent::confirmRequest(string $id, string $tool, array $input, string $preview): self`，type=`confirm_request`，data=`{id,tool,input,preview}`

- [ ] **Step 1: 写失败测试**（新建 `tests/StreamEventTest.php`）

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\StreamEvent;
use PHPUnit\Framework\TestCase;

final class StreamEventTest extends TestCase
{
    public function testConfirmRequestShape(): void
    {
        $e = StreamEvent::confirmRequest('cf_1', 'write_file', ['path' => 'a.php'], 'diff-text');
        self::assertSame('confirm_request', $e->type);
        self::assertSame('cf_1', $e->data['id']);
        self::assertSame('write_file', $e->data['tool']);
        self::assertSame(['path' => 'a.php'], $e->data['input']);
        self::assertSame('diff-text', $e->data['preview']);
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit --filter StreamEventTest`
Expected: FAIL（Call to undefined method confirmRequest）

- [ ] **Step 3: 实现**（在 `StreamEvent` 类内、`notice()` 之后追加）

```php
    /** 工具授权请求（server ask 模式）：前端弹确认 Dialog，经 POST /confirm/{id} 回传决定 */
    public static function confirmRequest(string $id, string $tool, array $input, string $preview): self
    {
        return new self('confirm_request', ['id' => $id, 'tool' => $tool, 'input' => $input, 'preview' => $preview]);
    }
```

并在类顶部 docblock 的事件列表追加一行：`*   confirm_request   {id, tool, input, preview}  工具授权请求（server）`

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit --filter StreamEventTest`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/LLM/StreamEvent.php tests/StreamEventTest.php
git commit -m "feat: StreamEvent 增加 confirm_request 授权事件"
```

---

### Task 3: ConfirmBroker — HTTP 确认协调（核心）

**Files:**
- Create: `src/Server/ConfirmBroker.php`
- Test: `tests/ConfirmBrokerTest.php`

**Interfaces:**
- Consumes: `StreamEvent::confirmRequest`（Task 2）；`React\Async\await`、`React\Promise\Deferred`、`React\EventLoop\Loop`（已有依赖）；`PhpAgent\Plugin\Diff\DiffRenderer`（best-effort，class_exists）
- Produces:
  - `setEmitter(\Closure $emit): void`（`$emit(StreamEvent): void`）
  - `request(string $tool, array $input): string`（ConfirmHook prompt；返回 y/n/a）
  - `resolve(string $id, string $decision): bool`
  - `discardActive(): void`
  - `pendingId(): ?string`（测试/清理用）

实现要点：`request` 在 Fiber 协程内被调用，`React\Async\await` 挂起协程不阻塞事件循环。超时用 `Loop::addTimer(120.0, …)` resolve `n`。preview：write_file 用 DiffRenderer（剥 ANSI、超 512KB 跳过、新文件摘要），run_command 给命令串，其余空。

- [ ] **Step 1: 写失败测试**（新建 `tests/ConfirmBrokerTest.php`）

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\LLM\StreamEvent;
use PhpAgent\Server\ConfirmBroker;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

final class ConfirmBrokerTest extends TestCase
{
    public function testResolveYesReturnsDecisionAndEmitsRequest(): void
    {
        $broker = new ConfirmBroker();
        $emitted = [];
        $broker->setEmitter(static function (StreamEvent $e) use (&$emitted): void {
            $emitted[] = $e;
            // 模拟前端立即同意：在事件循环下一拍 resolve
            Loop::futureTick(static function () use ($broker, $e): void {
                $broker->resolve($e->data['id'], 'y');
            });
        });

        $decision = null;
        Loop::futureTick(static function () use ($broker, &$decision): void {
            $decision = $broker->request('run_command', ['command' => 'ls']);
        });
        Loop::run();

        self::assertSame('y', $decision);
        self::assertCount(1, $emitted);
        self::assertSame('confirm_request', $emitted[0]->type);
        self::assertSame('run_command', $emitted[0]->data['tool']);
        self::assertSame('ls', $emitted[0]->data['preview']);
        self::assertNull($broker->pendingId());
    }

    public function testResolveUnknownIdReturnsFalse(): void
    {
        $broker = new ConfirmBroker();
        self::assertFalse($broker->resolve('nope', 'y'));
    }

    public function testDiscardActiveResolvesNo(): void
    {
        $broker = new ConfirmBroker();
        $broker->setEmitter(static function (StreamEvent $e) use ($broker): void {
            Loop::futureTick(static function () use ($broker): void {
                $broker->discardActive();
            });
        });
        $decision = null;
        Loop::futureTick(static function () use ($broker, &$decision): void {
            $decision = $broker->request('write_file', ['path' => 'x', 'content' => 'y']);
        });
        Loop::run();
        self::assertSame('n', $decision);
    }

    public function testTimeoutResolvesNo(): void
    {
        // 用可注入超时秒数的构造参数加速测试（生产默认 120）
        $broker = new ConfirmBroker(0.05);
        $broker->setEmitter(static function (StreamEvent $e): void {});
        $decision = null;
        Loop::futureTick(static function () use ($broker, &$decision): void {
            $decision = $broker->request('run_command', ['command' => 'sleep 1']);
        });
        Loop::run();
        self::assertSame('n', $decision);
        self::assertNull($broker->pendingId());
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit --filter ConfirmBrokerTest`
Expected: FAIL（Class ConfirmBroker not found）

- [ ] **Step 3: 实现**（新建 `src/Server/ConfirmBroker.php`）

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Server;

use PhpAgent\LLM\StreamEvent;
use React\Async;
use React\EventLoop\Loop;
use React\Promise\Deferred;

/**
 * HTTP 工具授权协调器（server ask 模式）。
 *
 * request() 作为 ConfirmHook 的 prompt：发 confirm_request SSE 事件 → 挂起当前
 * Fiber 协程（React\Async\await，不阻塞事件循环）→ 等 POST /confirm 经 resolve() 唤醒。
 * 超时（默认 120s）或流断开（discardActive）自动按拒绝（n）处理。
 *
 * MVP 假定每会话同一时刻只有一条流，故至多一个 pending confirm。
 */
final class ConfirmBroker
{
    /** @var array<string, Deferred> */
    private array $pending = [];
    /** @var array<string, \React\EventLoop\TimerInterface> */
    private array $timers = [];
    private ?\Closure $emit = null;
    private ?string $activeId = null;

    public function __construct(private readonly float $timeoutSeconds = 120.0)
    {
    }

    /** @param \Closure(StreamEvent): void $emit */
    public function setEmitter(\Closure $emit): void
    {
        $this->emit = $emit;
    }

    public function pendingId(): ?string
    {
        return $this->activeId;
    }

    /** ConfirmHook prompt：返回 'y' | 'n' | 'a' */
    public function request(string $tool, array $input): string
    {
        $id = uniqid('cf_', true);
        $preview = $this->buildPreview($tool, $input);
        if ($this->emit !== null) {
            ($this->emit)(StreamEvent::confirmRequest($id, $tool, $input, $preview));
        }

        $deferred = new Deferred();
        $this->pending[$id] = $deferred;
        $this->activeId = $id;
        $this->timers[$id] = Loop::addTimer($this->timeoutSeconds, function () use ($id): void {
            $this->settle($id, 'n');
        });

        try {
            $decision = Async\await($deferred->promise());
        } finally {
            $this->cleanup($id);
        }
        return in_array($decision, ['y', 'n', 'a'], true) ? $decision : 'n';
    }

    /** POST /confirm/{id}：命中 pending 返 true，未知/过期返 false */
    public function resolve(string $id, string $decision): bool
    {
        if (!isset($this->pending[$id])) {
            return false;
        }
        $this->settle($id, $decision);
        return true;
    }

    /** SSE 流断开：把当前 pending 按拒绝清理，防泄漏 */
    public function discardActive(): void
    {
        if ($this->activeId !== null) {
            $this->settle($this->activeId, 'n');
        }
    }

    private function settle(string $id, string $decision): void
    {
        if (isset($this->pending[$id])) {
            $this->pending[$id]->resolve($decision);
        }
    }

    private function cleanup(string $id): void
    {
        unset($this->pending[$id]);
        if (isset($this->timers[$id])) {
            Loop::cancelTimer($this->timers[$id]);
            unset($this->timers[$id]);
        }
        if ($this->activeId === $id) {
            $this->activeId = null;
        }
    }

    private function buildPreview(string $tool, array $input): string
    {
        if ($tool === 'run_command') {
            return (string) ($input['command'] ?? '');
        }
        if ($tool !== 'write_file') {
            return '';
        }
        $path = trim((string) ($input['path'] ?? ''));
        $content = (string) ($input['content'] ?? '');
        if ($path === '') {
            return '';
        }
        // 相对路径基于 workspace；broker 不持有 workspace，约定 input.path 已由前端/工具传相对或绝对。
        // 这里仅对绝对路径或可定位文件算 diff；定位不到则给新文件摘要。
        $abs = $path;
        if (!is_file($abs)) {
            $lines = substr_count($content, "\n") + 1;
            return '新文件 · ' . $lines . ' 行 · ' . strlen($content) . ' bytes';
        }
        if (!class_exists(\PhpAgent\Plugin\Diff\DiffRenderer::class)) {
            return '';
        }
        if ((filesize($abs) ?: 0) > \PhpAgent\Plugin\Diff\DiffRenderer::MAX_DIFF_BYTES) {
            return '(原文件过大，跳过预览)';
        }
        $old = (string) file_get_contents($abs);
        $diff = \PhpAgent\Plugin\Diff\DiffRenderer::render($old, $content, 40);
        return $this->stripAnsi($diff);
    }

    private function stripAnsi(string $s): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }
}
```

注意：`write_file` 的相对路径预览需要 workspace。为保持 broker 解耦，Task 5 装配时给 broker 注入 workspace（见 Task 5 对 `buildPreview` 的增补——构造参数 `?string $workspace = null`，相对路径拼 workspace）。本任务先按上述实现通过测试（测试用 run_command/新文件，不依赖 workspace）。

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit --filter ConfirmBrokerTest`
Expected: PASS（4 条）

- [ ] **Step 5: 提交**

```bash
git add src/Server/ConfirmBroker.php tests/ConfirmBrokerTest.php
git commit -m "feat: ConfirmBroker——SSE 授权请求 + Fiber 挂起等待 + 超时/断开按拒绝"
```

---

### Task 4: AgentServer — CORS + /status + 会话端点

**Files:**
- Modify: `src/Server/AgentServer.php`
- Test: `tests/ServerApiTest.php`（新建）

**Interfaces:**
- Consumes: `SessionStore`（list/load/delete）、`Config`（status 字段）、`React\Http\Message\Response`
- Produces: 路由 `GET /status`、`GET /sessions`、`GET /sessions/{id}`、`DELETE /sessions/{id}`、`OPTIONS *`；构造签名扩展为 `__construct(AgentLoop $loop, SessionStore $store, array $hooks, string $publicDir, Config $config, ?ConfirmBroker $broker = null)`

测试用 ReactPHP 直接调 `$server->handle($request)`（造 PSR-7 请求），不起 socket。

- [ ] **Step 1: 写失败测试**（新建 `tests/ServerApiTest.php`）

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Agent\ToolRegistry;
use PhpAgent\Config;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\Server\AgentServer;
use PhpAgent\Session\Store\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class ServerApiTest extends TestCase
{
    private function server(?InMemorySessionStore $store = null): AgentServer
    {
        $store ??= new InMemorySessionStore();
        $loop = new AgentLoop(new FakeLLMClient([]), new ToolRegistry(), 'sys', 5);
        $dir = sys_get_temp_dir() . '/php-agent-srv-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/.env', "AGENT_PROVIDER=fake\nAGENT_SESSION_STORE=memory\n");
        $config = Config::load($dir);
        return new AgentServer($loop, $store, [], $dir, $config);
    }

    public function testStatusReturnsFieldsAndCors(): void
    {
        $resp = $this->server()->handle(new ServerRequest('GET', 'http://localhost/status'));
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        $body = json_decode((string) $resp->getBody(), true);
        self::assertSame('fake', $body['provider']);
        self::assertSame('memory', $body['sessionStore']);
        self::assertSame(Config::VERSION, $body['version']);
        self::assertArrayHasKey('workspace', $body);
        self::assertArrayHasKey('permissions', $body);
        self::assertArrayHasKey('mcp', $body);
    }

    public function testOptionsPreflightReturns204(): void
    {
        $resp = $this->server()->handle(new ServerRequest('OPTIONS', 'http://localhost/agent'));
        self::assertSame(204, $resp->getStatusCode());
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $resp->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testSessionsListLoadDelete(): void
    {
        $store = new InMemorySessionStore();
        $store->save('s1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => '你好']]]]);
        $server = $this->server($store);

        $list = json_decode((string) $server->handle(new ServerRequest('GET', 'http://localhost/sessions'))->getBody(), true);
        self::assertCount(1, $list['sessions']);
        self::assertSame('s1', $list['sessions'][0]['id']);

        $load = json_decode((string) $server->handle(new ServerRequest('GET', 'http://localhost/sessions/s1'))->getBody(), true);
        self::assertSame('s1', $load['id']);
        self::assertCount(1, $load['messages']);

        $missing = json_decode((string) $server->handle(new ServerRequest('GET', 'http://localhost/sessions/nope'))->getBody(), true);
        self::assertSame([], $missing['messages']);

        $del = $server->handle(new ServerRequest('DELETE', 'http://localhost/sessions/s1'));
        self::assertSame(200, $del->getStatusCode());
        self::assertSame([], $store->load('s1'));
    }

    public function testUnknownRoute404WithCors(): void
    {
        $resp = $this->server()->handle(new ServerRequest('GET', 'http://localhost/nope'));
        self::assertSame(404, $resp->getStatusCode());
        self::assertSame('*', $resp->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit --filter ServerApiTest`
Expected: FAIL（构造参数不符 / 路由缺失）

- [ ] **Step 3: 实现**（重写 `src/Server/AgentServer.php` 的路由与构造；保留 handleAgent 主体，Task 5 再接 broker）

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Server;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Config;
use PhpAgent\Hook\HookPipeline;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Session\SessionStore;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * 本地 SSE 服务：桌面应用前端的对接面。
 *
 *   GET  /status            → 运行状态（provider/model/workspace/permissions/…）
 *   GET  /sessions          → 会话列表
 *   GET  /sessions/{id}     → 会话历史
 *   DELETE /sessions/{id}   → 删除会话
 *   POST /confirm/{id}      → 工具授权决定（Task 5）
 *   POST /agent             → SSE 流式对话
 *   GET  /                  → 内置极简聊天页（Web 用，桌面不走这里）
 *
 * 仅绑 127.0.0.1；所有响应带 CORS 头（前端 origin 与 API 不同源）。
 */
final class AgentServer
{
    public function __construct(
        private readonly AgentLoop $loop,
        private readonly SessionStore $store,
        private readonly array $hooks,
        private readonly string $publicDir,
        private readonly Config $config,
        private readonly ?ConfirmBroker $broker = null,
    ) {
    }

    public function handle(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        if ($method === 'OPTIONS') {
            return $this->cors(new Response(204));
        }

        if ($method === 'GET' && $path === '/status') {
            return $this->json(200, [
                'provider' => $this->config->provider(),
                'model' => $this->config->model(),
                'workspace' => $this->config->workspace(),
                'permissions' => $this->config->permissions(),
                'sessionStore' => $this->config->sessionStore(),
                'mcp' => $this->config->mcpEnabled(),
                'version' => Config::VERSION,
                'maxIterations' => $this->config->maxIterations(),
            ]);
        }

        if ($method === 'GET' && $path === '/sessions') {
            $limit = (int) ($request->getQueryParams()['limit'] ?? 50);
            $metas = array_map(static fn ($m) => $m->toArray(), $this->store->list(max(1, $limit)));
            return $this->json(200, ['sessions' => $metas]);
        }

        if (preg_match('#^/sessions/([^/]+)$#', $path, $m)) {
            $id = urldecode($m[1]);
            if ($method === 'GET') {
                return $this->json(200, ['id' => $id, 'messages' => $this->store->load($id)]);
            }
            if ($method === 'DELETE') {
                $this->store->delete($id);
                return $this->json(200, ['ok' => true]);
            }
        }

        if ($method === 'POST' && preg_match('#^/confirm/([^/]+)$#', $path, $m)) {
            return $this->handleConfirm(urldecode($m[1]), $request);
        }

        if ($method === 'POST' && $path === '/agent') {
            return $this->handleAgent($request);
        }

        if ($method === 'GET' && ($path === '/' || $path === '/index.html')) {
            $html = @file_get_contents($this->publicDir . '/index.html');
            if ($html === false) {
                return $this->cors(new Response(500, ['Content-Type' => 'text/plain'], 'index.html 缺失'));
            }
            return $this->cors(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
        }

        return $this->json(404, ['error' => 'Not Found']);
    }

    private function handleConfirm(string $id, \Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $decision = (string) ($body['decision'] ?? '');
        if (!in_array($decision, ['y', 'n', 'a'], true)) {
            return $this->json(400, ['error' => 'decision 必须是 y|n|a']);
        }
        if ($this->broker === null || !$this->broker->resolve($id, $decision)) {
            return $this->json(404, ['error' => '未知或已过期的确认 id']);
        }
        return $this->json(200, ['ok' => true]);
    }

    private function handleAgent(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return $this->json(400, ['error' => 'message 不能为空']);
        }

        $sid = (string) ($body['session_id'] ?? 'default');
        $messages = $this->store->load($sid);
        $stream = new ThroughStream();
        $broker = $this->broker;

        \React\Async\async(function () use ($stream, $sid, $message, $messages, $broker): void {
            try {
                $onEvent = function (StreamEvent $e) use ($stream): void {
                    $stream->write($this->sseFrame($e));
                    \React\Async\delay(0.001);
                };
                if ($broker !== null) {
                    $broker->setEmitter($onEvent);
                }
                $final = $this->loop->run($messages, $message, $onEvent);
                $this->store->save($sid, $messages);
                HookPipeline::fire($this->hooks, 'session_saved', ['sessionId' => $sid]);
                $stream->write($this->sseFrame(StreamEvent::finalAnswer($final)));
                $stream->end();
            } catch (\Throwable $e) {
                $stream->write($this->sseFrame(StreamEvent::error($e->getMessage())));
                $stream->end();
            } finally {
                $broker?->discardActive();
            }
        })();

        return $this->cors(new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], $stream));
    }

    private function sseFrame(StreamEvent $e): string
    {
        return 'data: ' . json_encode(['type' => $e->type, 'data' => $e->data], JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    private function json(int $status, array $data): Response
    {
        return $this->cors(new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_UNESCAPED_UNICODE)
        ));
    }

    private function cors(Response $resp): Response
    {
        return $resp
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit --filter ServerApiTest`
Expected: PASS（4 条）

- [ ] **Step 5: 全量回归 + 提交**

Run: `vendor/bin/phpunit`
Expected: PASS（原 101 + 新增；注意 bin/server 尚未改构造参数，但测试不依赖 bin/server）

```bash
git add src/Server/AgentServer.php tests/ServerApiTest.php
git commit -m "feat: AgentServer 补齐 CORS/status/会话端点 + confirm 路由骨架"
```

---

### Task 5: ConfirmBroker 注入 workspace + 确认流端到端测试

**Files:**
- Modify: `src/Server/ConfirmBroker.php`（构造加 `?string $workspace = null`，buildPreview 拼相对路径）
- Test: `tests/ConfirmBrokerTest.php`（追加 write_file diff 预览 + AgentLoop 集成）

**Interfaces:**
- Consumes: `ConfirmHook`（prompt 闭包）、`AgentLoop`、`FakeLLMClient`、`WriteFileTool`
- Produces: `new ConfirmBroker(float $timeoutSeconds = 120.0, ?string $workspace = null)`

- [ ] **Step 1: 写失败测试**（追加到 `tests/ConfirmBrokerTest.php`）

```php
public function testWriteFilePreviewShowsDiffForExistingFile(): void
{
    $ws = sys_get_temp_dir() . '/php-agent-broker-' . uniqid();
    mkdir($ws, 0777, true);
    file_put_contents($ws . '/a.txt', "line1\nline2\n");
    $broker = new ConfirmBroker(120.0, $ws);
    $preview = null;
    $broker->setEmitter(static function (StreamEvent $e) use ($broker, &$preview): void {
        $preview = $e->data['preview'];
        Loop::futureTick(static fn () => $broker->resolve($e->data['id'], 'n'));
    });
    Loop::futureTick(static fn () => $broker->request('write_file', ['path' => 'a.txt', 'content' => "line1\nline2-changed\n"]));
    Loop::run();
    self::assertStringContainsString('-line2', $preview);
    self::assertStringContainsString('+line2-changed', $preview);
    @unlink($ws . '/a.txt');
    @rmdir($ws);
}

public function testConfirmHookIntegrationDeniesThenAllows(): void
{
    $ws = sys_get_temp_dir() . '/php-agent-broker-int-' . uniqid();
    mkdir($ws, 0777, true);
    $registry = (new \PhpAgent\Agent\ToolRegistry())->register(new \PhpAgent\Tools\WriteFileTool($ws));
    $broker = new ConfirmBroker(120.0, $ws);

    // 第一次拒绝，第二次同意
    $answers = ['n', 'y'];
    $i = 0;
    $broker->setEmitter(static function (StreamEvent $e) use ($broker, &$answers, &$i): void {
        $a = $answers[$i++] ?? 'n';
        Loop::futureTick(static fn () => $broker->resolve($e->data['id'], $a));
    });
    $hook = new \PhpAgent\Hook\ConfirmHook(static fn (string $t, array $in): string => $broker->request($t, $in), $registry);

    $script = [
        [
            \PhpAgent\LLM\StreamEvent::toolStart('t1', 'write_file'),
            \PhpAgent\LLM\StreamEvent::toolEnd('t1', 'write_file', ['path' => 'x.txt', 'content' => 'hi']),
            \PhpAgent\LLM\StreamEvent::end('tool_use'),
        ],
        [
            \PhpAgent\LLM\StreamEvent::toolStart('t2', 'write_file'),
            \PhpAgent\LLM\StreamEvent::toolEnd('t2', 'write_file', ['path' => 'x.txt', 'content' => 'hi']),
            \PhpAgent\LLM\StreamEvent::end('tool_use'),
        ],
        [\PhpAgent\LLM\StreamEvent::text('done'), \PhpAgent\LLM\StreamEvent::end('end_turn')],
    ];
    $loop = new \PhpAgent\Agent\AgentLoop(new \PhpAgent\LLM\FakeLLMClient($script), $registry, 'sys', 10, [$hook]);

    $messages = [];
    $final = null;
    Loop::futureTick(static function () use ($loop, &$messages, &$final): void {
        $final = $loop->run($messages, '写文件');
    });
    Loop::run();

    self::assertSame('done', $final);
    self::assertStringStartsWith('DENIED:', $messages[2]['content'][0]['content']);
    self::assertFileExists($ws . '/x.txt');
    @unlink($ws . '/x.txt');
    @rmdir($ws);
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit --filter ConfirmBrokerTest`
Expected: FAIL（构造第二参数不存在 / 相对路径预览未拼 workspace）

- [ ] **Step 3: 实现**（修改 `ConfirmBroker` 构造与 buildPreview）

构造改为：

```php
    public function __construct(
        private readonly float $timeoutSeconds = 120.0,
        private readonly ?string $workspace = null,
    ) {
    }
```

buildPreview 中定位文件处改为：

```php
        $abs = $path;
        if (!str_starts_with($path, '/') && $this->workspace !== null) {
            $abs = rtrim($this->workspace, '/') . '/' . $path;
        }
        if (!is_file($abs)) {
            $lines = substr_count($content, "\n") + 1;
            return '新文件 · ' . $lines . ' 行 · ' . strlen($content) . ' bytes';
        }
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit --filter ConfirmBrokerTest`
Expected: PASS（6 条）

- [ ] **Step 5: 提交**

```bash
git add src/Server/ConfirmBroker.php tests/ConfirmBrokerTest.php
git commit -m "feat: ConfirmBroker 注入 workspace 算 write_file diff 预览 + 确认流集成测试"
```

---

### Task 6: bin/server 装配 — 端口 0 + AGENT_LISTENING + ConfirmHook + AGENT_UI

**Files:**
- Modify: `bin/server`

**Interfaces:**
- Consumes: `ConfirmBroker`、`ConfirmHook`、`Config::serverPort()`（Task 1）、`AgentServer` 新构造（Task 4）
- Produces: 可被壳 spawn 的 sidecar 入口；stdout 含 `AGENT_LISTENING port=<port>`

- [ ] **Step 1: 改写 bin/server**（完整替换）

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHP Agent 本地服务入口（桌面 sidecar / Web 对接面）。
 *
 *   php bin/server                        按 .env 启动
 *   AGENT_SERVER_PORT=0 php bin/server    自动选端口（桌面壳用），stdout 打 AGENT_LISTENING port=<port>
 *   AGENT_PROVIDER=fake php bin/server    离线启动
 */

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Agent\DefaultSystemPrompt;
use PhpAgent\Config;
use PhpAgent\Hook\ConfirmHook;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;
use PhpAgent\Server\AgentServer;
use PhpAgent\Server\ConfirmBroker;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);
putenv('AGENT_UI=server'); // diff 预览钩子据此抑制 stdout echo
$config = Config::load($root);

$kernel = new AgentKernel($config);
PluginManager::load($kernel, $root);

$client = $kernel->provider($config->provider());

// 授权确认：ask 模式装 ConfirmHook，prompt 走 HTTP（ConfirmBroker）
$broker = null;
$hooks = $kernel->hooks();
if ($config->permissions() === 'ask') {
    $broker = new ConfirmBroker(120.0, $config->workspace());
    $hooks[] = new ConfirmHook(
        static fn (string $tool, array $input): string => $broker->request($tool, $input),
        $kernel->toolRegistry()
    );
}

$loop = new AgentLoop(
    $client,
    $kernel->toolRegistry(),
    DefaultSystemPrompt::text($config->workspace()),
    $config->maxIterations(),
    $hooks
);

$storeClass = get_class($kernel->sessionStore());
$server = new AgentServer($loop, $kernel->sessionStore(), $hooks, $root . '/public', $config, $broker);

$http = new HttpServer(Loop::get(), $server->handle(...));
$port = $config->serverPort();
$socket = new SocketServer('127.0.0.1:' . $port, [], Loop::get());
$http->listen($socket);

// 端口 0：从实际绑定地址解析端口，打机器可读行供桌面壳解析
$actual = $port;
if ($port === 0) {
    $addr = (string) $socket->getAddress(); // tcp://127.0.0.1:54321
    $actual = (int) substr($addr, (int) strrpos($addr, ':') + 1);
    echo "AGENT_LISTENING port={$actual}\n";
}

echo "PHP Agent 本地服务已启动\n";
echo "  网页聊天: http://127.0.0.1:{$actual}\n";
echo "  SSE 接口: POST http://127.0.0.1:{$actual}/agent  {\"message\": \"...\", \"session_id\": \"...\"}\n";
echo "  provider: {$config->provider()}  model: " . ($config->model() ?: '(未配置)') . "\n";
echo '  会话存储: ' . substr($storeClass, (int) strrpos($storeClass, '\\') + 1) . "\n";
echo "  权限模式: {$config->permissions()}\n";
echo "Ctrl+C 退出\n";

Loop::get()->run();
```

- [ ] **Step 2: 手动验证端口协商 + 状态接口**

Run（后台起服务，fake provider）:
```bash
AGENT_PROVIDER=fake AGENT_SERVER_PORT=0 AGENT_SESSION_STORE=memory php bin/server > /tmp/php-agent-server.log 2>&1 &
sleep 1
PORT=$(grep -o 'AGENT_LISTENING port=[0-9]*' /tmp/php-agent-server.log | grep -o '[0-9]*')
echo "port=$PORT"
curl -s "http://127.0.0.1:$PORT/status"
```
Expected: 打印 `port=<非0>`；`/status` 返回 JSON 含 `"provider":"fake"`、`"permissions":"ask"`、`"version":"0.2.0"`。

- [ ] **Step 3: 验证授权确认流（curl 模拟前端）**

Run:
```bash
# 触发 write_file（fake demo 第一轮即写文件），SSE 里应出现 confirm_request
curl -sN -X POST "http://127.0.0.1:$PORT/agent" -H 'Content-Type: application/json' \
  -d '{"message":"演示","session_id":"t1"}' > /tmp/sse.log &
sleep 1
CID=$(grep -o '"id":"cf_[^"]*"' /tmp/sse.log | head -1 | sed 's/.*:"\(.*\)"/\1/')
echo "confirm=$CID"
curl -s -X POST "http://127.0.0.1:$PORT/confirm/$CID" -H 'Content-Type: application/json' -d '{"decision":"y"}'
sleep 1
grep -c 'tool_result' /tmp/sse.log
kill %1 %2 2>/dev/null
```
Expected: 打印 `confirm=cf_…`；confirm 返回 `{"ok":true}`；SSE 含 tool_result（工具获准执行）。

- [ ] **Step 4: 关闭服务 + 全量回归**

Run:
```bash
kill %1 2>/dev/null; pkill -f 'bin/server' 2>/dev/null
vendor/bin/phpunit
```
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add bin/server
git commit -m "feat: bin/server 装配端口协商/AGENT_LISTENING/HTTP 授权确认/AGENT_UI=server"
```

---

### Task 7: DiffPreviewHook server 抑制 + DiffPlugin 按 AGENT_UI 选择

**Files:**
- Modify: `plugins/diff/src/DiffPreviewHook.php`
- Modify: `plugins/diff/src/DiffPlugin.php`
- Test: `tests/DiffPluginTest.php`（追加回归）

**Interfaces:**
- Consumes: `getenv('AGENT_UI')`
- Produces: `DiffPreviewHook::__construct(string $workspace, ?\Closure $output = null)`（null=echo，CLI 不变）

- [ ] **Step 1: 写失败测试**（追加到 `tests/DiffPluginTest.php`；先读现有文件确认命名空间与 setUp）

```php
public function testPreviewHookSilentOutputSuppressesEcho(): void
{
    $ws = sys_get_temp_dir() . '/php-agent-diff-' . uniqid();
    mkdir($ws, 0777, true);
    $hook = new \PhpAgent\Plugin\Diff\DiffPreviewHook($ws, static function (string $s): void {});
    ob_start();
    $hook->handle('before_tool_call', ['tool' => 'write_file', 'input' => ['path' => 'new.php', 'content' => "<?php\n"]]);
    $out = ob_get_clean();
    self::assertSame('', $out);
    @rmdir($ws);
}

public function testPreviewHookDefaultEchoes(): void
{
    $ws = sys_get_temp_dir() . '/php-agent-diff-' . uniqid();
    mkdir($ws, 0777, true);
    $hook = new \PhpAgent\Plugin\Diff\DiffPreviewHook($ws);
    ob_start();
    $hook->handle('before_tool_call', ['tool' => 'write_file', 'input' => ['path' => 'new.php', 'content' => "<?php\n"]]);
    $out = ob_get_clean();
    self::assertStringContainsString('变更预览', $out);
    @rmdir($ws);
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit --filter DiffPluginTest`
Expected: FAIL（构造第二参数不存在）

- [ ] **Step 3: 实现**

`DiffPreviewHook.php`：构造加 output 回调，所有 `echo` 改为经回调。

```php
    /** @param \Closure(string): void|null $output null = 默认 echo（CLI）；server 传 no-op */
    public function __construct(
        private readonly string $workspace,
        private readonly ?\Closure $output = null,
    ) {
    }

    private function write(string $s): void
    {
        if ($this->output !== null) {
            ($this->output)($s);
        } else {
            echo $s;
        }
    }
```

把 handle() 内每处 `echo "…";` 替换为 `$this->write("…");`（含变更预览标题、新文件摘要、过大提示、无变化提示、diff 正文）。indent() 不变。

`DiffPlugin.php` register() 改为：

```php
    public function register(AgentKernel $kernel): void
    {
        $workspace = $kernel->config()->workspace();
        $kernel->registerTool(DiffWriteFileTool::wrap($workspace));
        $output = getenv('AGENT_UI') === 'server' ? static function (string $s): void {} : null;
        $kernel->addHook(new DiffPreviewHook($workspace, $output));
    }
```

- [ ] **Step 4: 运行确认通过 + 全量回归**

Run: `vendor/bin/phpunit`
Expected: PASS（CLI echo 回归绿）

- [ ] **Step 5: 提交**

```bash
git add plugins/diff/src/DiffPreviewHook.php plugins/diff/src/DiffPlugin.php tests/DiffPluginTest.php
git commit -m "feat: diff 预览钩子支持 server 静默输出（AGENT_UI），CLI 行为不变"
```

---

## Phase 2 — Rust Tauri 壳

> 前置：本机已装 Rust 1.97、Node 22。Phase 2 结束时 `npm run tauri dev` 应能弹出窗口并自动拉起 sidecar（前端暂为占位页，Phase 3 替换）。

### Task 8: 脚手架 desktop/ + src-tauri（可弹窗）

**Files:**
- Create: `desktop/package.json`、`desktop/vite.config.ts`、`desktop/tsconfig.json`、`desktop/tsconfig.node.json`、`desktop/index.html`、`desktop/src/main.tsx`、`desktop/src/App.tsx`、`desktop/src/index.css`
- Create: `desktop/src-tauri/Cargo.toml`、`tauri.conf.json`、`build.rs`、`capabilities/default.json`、`src/main.rs`、`src/lib.rs`

**Interfaces:**
- Produces: `npm run tauri dev` 起 Vite(1420)+壳；窗口加载前端；`src-tauri` 可 `cargo check`

- [ ] **Step 1: 前端脚手架文件**

`desktop/package.json`:
```json
{
  "name": "php-agent-desktop",
  "private": true,
  "version": "0.2.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "preview": "vite preview",
    "test": "vitest run",
    "tauri": "tauri"
  },
  "dependencies": {
    "@tauri-apps/api": "^2",
    "@tauri-apps/plugin-dialog": "^2",
    "react": "^18.3.1",
    "react-dom": "^18.3.1",
    "react-markdown": "^9",
    "remark-gfm": "^4",
    "lucide-react": "^0.460.0",
    "zustand": "^5"
  },
  "devDependencies": {
    "@tauri-apps/cli": "^2",
    "@types/react": "^18.3.12",
    "@types/react-dom": "^18.3.1",
    "@vitejs/plugin-react": "^4.3.4",
    "typescript": "^5.6.3",
    "vite": "^6",
    "vitest": "^2",
    "@testing-library/react": "^16",
    "@testing-library/jest-dom": "^6",
    "jsdom": "^25",
    "tailwindcss": "^3.4.15",
    "postcss": "^8.4.49",
    "autoprefixer": "^10.4.20"
  }
}
```

`desktop/vite.config.ts`:
```ts
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  clearScreen: false,
  server: { port: 1420, strictPort: true },
  envPrefix: ["VITE_", "TAURI_"],
  build: { target: "es2021", outDir: "dist" },
  test: { environment: "jsdom", globals: true, setupFiles: ["./src/test-setup.ts"] },
});
```

`desktop/tsconfig.json`:
```json
{
  "compilerOptions": {
    "target": "ES2021",
    "useDefineForClassFields": true,
    "lib": ["ES2021", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "allowImportingTsExtensions": true,
    "resolveJsonModule": true,
    "isolatedModules": true,
    "noEmit": true,
    "jsx": "react-jsx",
    "strict": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true,
    "noFallthroughCasesInSwitch": true,
    "types": ["vitest/globals", "@testing-library/jest-dom"]
  },
  "include": ["src"],
  "references": [{ "path": "./tsconfig.node.json" }]
}
```

`desktop/tsconfig.node.json`:
```json
{
  "compilerOptions": {
    "composite": true,
    "skipLibCheck": true,
    "module": "ESNext",
    "moduleResolution": "bundler",
    "allowSyntheticDefaultImports": true,
    "strict": true
  },
  "include": ["vite.config.ts"]
}
```

`desktop/index.html`:
```html
<!doctype html>
<html lang="zh-CN" class="dark">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>PHP Agent</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.tsx"></script>
  </body>
</html>
```

`desktop/src/main.tsx`:
```tsx
import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App";
import "./index.css";

ReactDOM.createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
```

`desktop/src/App.tsx`（占位，Phase 3 替换）:
```tsx
export default function App() {
  return <div style={{ padding: 24, color: "#E8EAF2" }}>PHP Agent 桌面壳已启动（前端待 Phase 3 实现）</div>;
}
```

`desktop/src/index.css`（占位，Task 11 扩展）:
```css
:root { color-scheme: dark; }
body { margin: 0; background: #0B0D12; font-family: system-ui, sans-serif; }
```

`desktop/src/test-setup.ts`:
```ts
import "@testing-library/jest-dom/vitest";
```

`desktop/postcss.config.js`:
```js
export default { plugins: { tailwindcss: {}, autoprefixer: {} } };
```

`desktop/tailwind.config.js`（Task 11 填主题；先建空壳）:
```js
/** @type {import('tailwindcss').Config} */
export default { darkMode: "class", content: ["./index.html", "./src/**/*.{ts,tsx}"], theme: { extend: {} }, plugins: [] };
```

- [ ] **Step 2: Tauri 壳文件**

`desktop/src-tauri/Cargo.toml`:
```toml
[package]
name = "php-agent-desktop"
version = "0.2.0"
description = "PHP Agent Desktop"
edition = "2021"

[lib]
name = "php_agent_desktop_lib"
crate-type = ["staticlib", "cdylib", "rlib"]

[build-dependencies]
tauri-build = { version = "2", features = [] }

[dependencies]
tauri = { version = "2", features = [] }
tauri-plugin-dialog = "2"
tauri-plugin-single-instance = "2"
serde = { version = "1", features = ["derive"] }
serde_json = "1"

[target.'cfg(unix)'.dependencies]
libc = "0.2"

[profile.release]
codegen-units = 1
lto = true
strip = true
```

`desktop/src-tauri/build.rs`:
```rust
fn main() {
    tauri_build::build()
}
```

`desktop/src-tauri/tauri.conf.json`:
```json
{
  "$schema": "https://schema.tauri.app/config/2",
  "productName": "PHP Agent",
  "version": "0.2.0",
  "identifier": "dev.phpagent.desktop",
  "build": {
    "beforeDevCommand": "npm run dev",
    "devUrl": "http://localhost:1420",
    "beforeBuildCommand": "npm run build",
    "frontendDist": "../dist"
  },
  "app": {
    "windows": [
      {
        "title": "PHP Agent",
        "width": 1200,
        "height": 800,
        "minWidth": 900,
        "minHeight": 600,
        "theme": "Dark",
        "decorations": true
      }
    ],
    "security": { "csp": null }
  },
  "bundle": {
    "active": true,
    "targets": "all",
    "icon": ["icons/icon.png"]
  },
  "plugins": {}
}
```

`desktop/src-tauri/capabilities/default.json`:
```json
{
  "$schema": "../gen/schemas/desktop-schema.json",
  "identifier": "default",
  "description": "default capability",
  "windows": ["main"],
  "permissions": ["core:default", "dialog:default", "dialog:allow-open"]
}
```

`desktop/src-tauri/src/main.rs`:
```rust
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

fn main() {
    php_agent_desktop_lib::run()
}
```

`desktop/src-tauri/src/lib.rs`（Task 9/10 扩展 sidecar 与 commands；先最小可运行）:
```rust
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            use tauri::Manager;
            if let Some(w) = app.get_webview_window("main") {
                let _ = w.set_focus();
            }
        }))
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
```

注意：`bundle.icon` 需一个 PNG。生成占位图标：`desktop/src-tauri/icons/icon.png`（可用任意 512×512 PNG；或先临时把 bundle.active 设 false 跳过图标，Task 20 再补）。为不阻塞，先把 `tauri.conf.json` 的 `bundle.active` 设为 `false`，dev 不需要图标。

- [ ] **Step 3: 安装依赖并验证编译**

Run:
```bash
cd desktop && npm install
cd src-tauri && cargo check
```
Expected: npm 安装成功；`cargo check` 通过（首次拉 crate 较慢）。

- [ ] **Step 4: 验证弹窗**

Run: `cd desktop && npm run tauri dev`（手动观察）
Expected: 弹出「PHP Agent」窗口，显示占位文字。确认后 Ctrl+C 退出。

- [ ] **Step 5: 提交**

```bash
git add desktop
git commit -m "feat: 桌面壳脚手架——Tauri 2 + Vite/React 可弹窗"
```

---

### Task 9: sidecar 管理（spawn/端口解析/健康/进程组清理）

**Files:**
- Create: `desktop/src-tauri/src/sidecar.rs`
- Modify: `desktop/src-tauri/src/lib.rs`

**Interfaces:**
- Produces:
  - `parse_port_line(line: &str) -> Option<u16>`（纯函数，单测）
  - `Sidecar` struct：`start(workspace: &str, project_root: &Path) -> Result<Sidecar>`、`port() -> u16`、`stop(&mut self)`、`is_alive(&mut self) -> bool`
  - 内部：spawn `php bin/server`，env 注入，stdout 读线程解析端口，TcpStream 探活

- [ ] **Step 1: 写失败单测**（`sidecar.rs` 底部 `#[cfg(test)]`）

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_port_line() {
        assert_eq!(parse_port_line("AGENT_LISTENING port=54321"), Some(54321));
        assert_eq!(parse_port_line("AGENT_LISTENING port=0"), Some(0));
        assert_eq!(parse_port_line("  AGENT_LISTENING port=8091  "), Some(8091));
        assert_eq!(parse_port_line("PHP Agent 本地服务已启动"), None);
        assert_eq!(parse_port_line("AGENT_LISTENING port=abc"), None);
        assert_eq!(parse_port_line("AGENT_LISTENING port=99999"), None);
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop/src-tauri && cargo test`
Expected: FAIL（parse_port_line 未定义）

- [ ] **Step 3: 实现 sidecar.rs**

```rust
use std::io::{BufRead, BufReader};
use std::net::TcpStream;
use std::path::Path;
use std::process::{Child, Command, Stdio};
use std::sync::mpsc;
use std::time::Duration;

/// 从 stdout 行解析 `AGENT_LISTENING port=<port>`（纯函数，可单测）
pub fn parse_port_line(line: &str) -> Option<u16> {
    let line = line.trim();
    let rest = line.strip_prefix("AGENT_LISTENING port=")?;
    rest.trim().parse::<u16>().ok()
}

pub struct Sidecar {
    child: Child,
    port: u16,
}

impl Sidecar {
    /// spawn `php bin/server`，注入 env，阻塞等待端口行 + 探活
    pub fn start(workspace: &str, project_root: &Path) -> Result<Sidecar, String> {
        let mut cmd = Command::new("php");
        cmd.arg("bin/server")
            .current_dir(project_root)
            .env("AGENT_SERVER_PORT", "0")
            .env("AGENT_WORKSPACE", workspace)
            .env("AGENT_PERMISSIONS", "ask")
            .env("AGENT_UI", "server")
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit());

        #[cfg(unix)]
        {
            use std::os::unix::process::CommandExt;
            cmd.process_group(0); // 独立进程组，便于整组清理
        }

        let mut child = cmd.spawn().map_err(|e| format!("无法启动 php sidecar: {e}"))?;
        let stdout = child.stdout.take().ok_or("无法读取 sidecar stdout")?;
        let (tx, rx) = mpsc::channel::<u16>();
        std::thread::spawn(move || {
            let reader = BufReader::new(stdout);
            for line in reader.lines().map_while(Result::ok) {
                if let Some(port) = parse_port_line(&line) {
                    let _ = tx.send(port);
                    break;
                }
            }
        });

        let port = rx
            .recv_timeout(Duration::from_secs(15))
            .map_err(|_| "sidecar 未在 15s 内报告端口".to_string())?;

        // 探活：端口可连接即就绪（/status 由前端拉取）
        let addr = format!("127.0.0.1:{port}");
        let mut ready = false;
        for _ in 0..50 {
            if TcpStream::connect(&addr).is_ok() {
                ready = true;
                break;
            }
            std::thread::sleep(Duration::from_millis(100));
        }
        if !ready {
            let _ = kill_group(&mut child);
            return Err("sidecar 端口无法连接".into());
        }
        Ok(Sidecar { child, port })
    }

    pub fn port(&self) -> u16 {
        self.port
    }

    pub fn is_alive(&mut self) -> bool {
        matches!(self.child.try_wait(), Ok(None))
    }

    pub fn stop(&mut self) {
        let _ = kill_group(&mut self.child);
        let _ = self.child.wait();
    }
}

impl Drop for Sidecar {
    fn drop(&mut self) {
        self.stop();
    }
}

#[cfg(unix)]
fn kill_group(child: &mut Child) -> std::io::Result<()> {
    let pid = child.id() as i32;
    // 杀整个进程组（php 及其 MCP stdio 子进程）
    let ret = unsafe { libc::killpg(pid, libc::SIGKILL) };
    if ret != 0 {
        // 回退：单进程 kill
        child.kill()?;
    }
    Ok(())
}

#[cfg(not(unix))]
fn kill_group(child: &mut Child) -> std::io::Result<()> {
    child.kill()
}
```

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop/src-tauri && cargo test`
Expected: PASS（parses_port_line）

- [ ] **Step 5: 提交**

```bash
git add desktop/src-tauri/src/sidecar.rs
git commit -m "feat: sidecar 管理——spawn/端口解析/探活/进程组清理 + 端口解析单测"
```

---

### Task 10: Tauri commands + events + 状态管理 + workspace 持久化

**Files:**
- Modify: `desktop/src-tauri/src/lib.rs`

**Interfaces:**
- Consumes: `Sidecar`（Task 9）、`tauri_plugin_dialog`
- Produces: commands `get_backend_url`/`get_workspace`/`pick_workspace`/`restart_backend`；events `backend://ready`/`backend://disconnected`/`backend://error`；持久化 `~/.php-agent/desktop.json`

- [ ] **Step 1: 实现 lib.rs**（完整替换）

```rust
mod sidecar;

use sidecar::Sidecar;
use std::path::PathBuf;
use std::sync::Mutex;
use tauri::{Emitter, Manager, State};

struct AppState {
    sidecar: Mutex<Option<Sidecar>>,
    workspace: Mutex<String>,
    project_root: PathBuf,
}

fn desktop_json_path() -> PathBuf {
    let home = std::env::var("HOME").unwrap_or_else(|_| ".".into());
    PathBuf::from(home).join(".php-agent").join("desktop.json")
}

fn load_workspace(default: &str) -> String {
    let path = desktop_json_path();
    if let Ok(text) = std::fs::read_to_string(&path) {
        if let Ok(v) = serde_json::from_str::<serde_json::Value>(&text) {
            if let Some(ws) = v.get("workspace").and_then(|w| w.as_str()) {
                if std::path::Path::new(ws).is_dir() {
                    return ws.to_string();
                }
            }
        }
    }
    default.to_string()
}

fn save_workspace(ws: &str) {
    let path = desktop_json_path();
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let _ = std::fs::write(&path, serde_json::json!({ "workspace": ws }).to_string());
}

fn start_sidecar(state: &AppState, app: &tauri::AppHandle) {
    let ws = state.workspace.lock().unwrap().clone();
    // 停旧
    {
        let mut guard = state.sidecar.lock().unwrap();
        if let Some(mut old) = guard.take() {
            old.stop();
        }
    }
    match Sidecar::start(&ws, &state.project_root) {
        Ok(sc) => {
            let port = sc.port();
            *state.sidecar.lock().unwrap() = Some(sc);
            let _ = app.emit("backend://ready", serde_json::json!({ "port": port }));
        }
        Err(e) => {
            let _ = app.emit("backend://error", serde_json::json!({ "message": e }));
        }
    }
}

#[tauri::command]
fn get_backend_url(state: State<AppState>) -> Result<String, String> {
    let guard = state.sidecar.lock().unwrap();
    match guard.as_ref() {
        Some(sc) => Ok(format!("http://127.0.0.1:{}", sc.port())),
        None => Err("backend 未就绪".into()),
    }
}

#[tauri::command]
fn get_workspace(state: State<AppState>) -> String {
    state.workspace.lock().unwrap().clone()
}

#[tauri::command]
async fn pick_workspace(app: tauri::AppHandle, state: State<'_, AppState>) -> Result<Option<String>, String> {
    use tauri_plugin_dialog::DialogExt;
    let picked = app.dialog().file().blocking_pick_folder();
    match picked {
        Some(path) => {
            let ws = path.to_string();
            if !std::path::Path::new(&ws).is_dir() {
                return Err("所选目录不存在".into());
            }
            *state.workspace.lock().unwrap() = ws.clone();
            save_workspace(&ws);
            start_sidecar(&state, &app);
            Ok(Some(ws))
        }
        None => Ok(None),
    }
}

#[tauri::command]
fn restart_backend(app: tauri::AppHandle, state: State<AppState>) -> Result<(), String> {
    start_sidecar(&state, &app);
    Ok(())
}

pub fn run() {
    // 项目根 = src-tauri 的上两级（desktop/src-tauri → desktop → 项目根）
    let project_root = std::env::current_dir()
        .ok()
        .and_then(|d| d.parent().map(|p| p.to_path_buf()))
        .and_then(|d| d.parent().map(|p| p.to_path_buf()))
        .unwrap_or_else(|| PathBuf::from("."));
    // dev 时 cwd 通常是 desktop/；更稳妥用 CARGO_MANIFEST_DIR 编译期定位
    let project_root = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .parent()
        .and_then(|p| p.parent())
        .map(|p| p.to_path_buf())
        .unwrap_or(project_root);

    let default_ws = project_root.to_string_lossy().to_string();
    let workspace = load_workspace(&default_ws);

    tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            if let Some(w) = app.get_webview_window("main") {
                let _ = w.set_focus();
            }
        }))
        .manage(AppState {
            sidecar: Mutex::new(None),
            workspace: Mutex::new(workspace),
            project_root,
        })
        .invoke_handler(tauri::generate_handler![
            get_backend_url,
            get_workspace,
            pick_workspace,
            restart_backend
        ])
        .setup(|app| {
            let state = app.state::<AppState>();
            let handle = app.handle().clone();
            start_sidecar(&state, &handle);
            Ok(())
        })
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
```

注意：`project_root` 用 `CARGO_MANIFEST_DIR`（= desktop/src-tauri）上两级定位项目根，dev/build 都稳定。`pick_workspace` 用 `blocking_pick_folder` 需在 async command 内（Tauri 会在阻塞线程池跑）；若编译报 trait 约束，改用 `app.dialog().file().pick_folder(callback)` 回调式并在回调里 emit。实现时以 `cargo check` 为准微调。

- [ ] **Step 2: 编译验证**

Run: `cd desktop/src-tauri && cargo check`
Expected: 通过（按编译器提示微调 dialog API 形态）

- [ ] **Step 3: 手动验证 sidecar 拉起 + 端口**

Run: `cd desktop && npm run tauri dev`（手动）
Expected: 窗口弹出；控制台/日志可见 sidecar 启动；`~/.php-agent/desktop.json` 生成。前端占位页暂不调用 command，Task 12 起接入。Ctrl+C 退出后 `pgrep -f bin/server` 应无残留。

- [ ] **Step 4: 验证无孤儿进程**

Run: `pgrep -fl 'bin/server' || echo "no orphan"`
Expected: `no orphan`

- [ ] **Step 5: 提交**

```bash
git add desktop/src-tauri/src/lib.rs
git commit -m "feat: Tauri commands/events——backend url/workspace 选择/重启 + 持久化 + 单实例"
```

---

## Phase 3 — React 前端

### Task 11: Tailwind 主题 + shadcn 初始化 + 设计 token + 自绘滚动条

**Files:**
- Modify: `desktop/tailwind.config.js`、`desktop/src/index.css`
- Create: `desktop/components.json`、`desktop/src/lib/utils.ts`、shadcn 原语（CLI 生成）

**Interfaces:**
- Produces: `cn(...)` 工具；主题 token（spec 调色板）；shadcn 原语 button/dialog/scroll-area/tooltip/dropdown-menu/badge/input/textarea

- [ ] **Step 1: 初始化 shadcn**

Run:
```bash
cd desktop
npx shadcn@latest init -d
npx shadcn@latest add button dialog scroll-area tooltip dropdown-menu badge input textarea separator
```
Expected: 生成 `components.json`、`src/lib/utils.ts`、`src/components/ui/*`；init 会改写 index.css/tailwind.config（下一步覆盖为我们的主题）。

- [ ] **Step 2: 覆盖 tailwind.config.js 主题**

```js
/** @type {import('tailwindcss').Config} */
export default {
  darkMode: "class",
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        bg: "#0B0D12",
        surface: "#141821",
        elevated: "#1A1F2B",
        primary: { DEFAULT: "#8B8DF6", deep: "#5B5BD6" },
        info: "#38BDF8",
        success: "#4ADE80",
        warning: "#FBBF24",
        danger: "#FB7185",
        thinking: "#E879F9",
        ink: "#E8EAF2",
        muted: "#8A90A6",
        border: "#262B38",
      },
      fontFamily: {
        sans: ["Geist", "Inter", "system-ui", "PingFang SC", "sans-serif"],
        mono: ["JetBrains Mono", "ui-monospace", "Menlo", "monospace"],
      },
      keyframes: {
        blink: { "0%,100%": { opacity: "1" }, "50%": { opacity: "0" } },
        "fade-up": { from: { opacity: "0", transform: "translateY(6px)" }, to: { opacity: "1", transform: "translateY(0)" } },
      },
      animation: { blink: "blink 1s steps(1) infinite", "fade-up": "fade-up .25s ease both" },
    },
  },
  plugins: [require("tailwindcss-animate")],
};
```

Run: `cd desktop && npm install tailwindcss-animate`

- [ ] **Step 3: 覆盖 index.css（token + 基础 + 自绘滚动条）**

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

:root {
  --bg: #0b0d12;
  --surface: #141821;
  --elevated: #1a1f2b;
  --primary: #8b8df6;
  --primary-deep: #5b5bd6;
  --info: #38bdf8;
  --success: #4ade80;
  --warning: #fbbf24;
  --danger: #fb7185;
  --thinking: #e879f9;
  --text: #e8eaf2;
  --muted: #8a90a6;
  --border: #262b38;
  color-scheme: dark;
}

html, body, #root { height: 100%; }
body {
  margin: 0;
  background: var(--bg);
  color: var(--text);
  font-family: Geist, Inter, system-ui, "PingFang SC", sans-serif;
  -webkit-font-smoothing: antialiased;
}

/* 自绘滚动条（按本调色板，不用浏览器默认） */
* { scrollbar-width: thin; scrollbar-color: var(--elevated) transparent; }
*::-webkit-scrollbar { width: 10px; height: 10px; }
*::-webkit-scrollbar-track { background: transparent; }
*::-webkit-scrollbar-thumb {
  background: var(--elevated);
  border-radius: 8px;
  border: 2px solid var(--bg);
}
*::-webkit-scrollbar-thumb:hover { background: #2a3040; }

::selection { background: color-mix(in srgb, var(--primary) 35%, transparent); }
```

- [ ] **Step 4: 确认 utils.ts 存在**

`desktop/src/lib/utils.ts`（shadcn 已生成；若缺则建）:
```ts
import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
```
Run: `cd desktop && npm install clsx tailwind-merge`（若 shadcn 未装）

- [ ] **Step 5: 验证构建 + 提交**

Run: `cd desktop && npm run build`
Expected: 构建通过。

```bash
git add desktop
git commit -m "feat: 前端主题——PHP 靛紫调色板 token + shadcn 原语 + 自绘滚动条"
```

---

### Task 12: lib 层 — api / sse / tauri / diff + SSE 解析测试

**Files:**
- Create: `desktop/src/lib/api.ts`、`sse.ts`、`tauri.ts`、`diff.ts`
- Test: `desktop/src/lib/sse.test.ts`、`diff.test.ts`

**Interfaces:**
- Produces:
  - `api.ts`：`setBaseUrl(url)`、`getStatus()`、`listSessions(limit?)`、`loadSession(id)`、`deleteSession(id)`、`confirm(id, decision)`、`agentStream(message, sessionId, signal): AsyncGenerator<AgentEvent>`
  - `sse.ts`：`parseSseFrames(buffer: string): { events: AgentEvent[]; rest: string }`（纯函数，单测）
  - `tauri.ts`：`getBackendUrl()`、`getWorkspace()`、`pickWorkspace()`、`restartBackend()`、`onBackendEvent(cb)`
  - `diff.ts`：`parseDiffLines(text): DiffLine[]`（`{kind:'add'|'del'|'meta'|'ctx', text}`）
  - 类型 `AgentEvent = { type: string; data: any }`

- [ ] **Step 1: 写失败测试**

`desktop/src/lib/sse.test.ts`:
```ts
import { describe, it, expect } from "vitest";
import { parseSseFrames } from "./sse";

describe("parseSseFrames", () => {
  it("parses complete frames and keeps partial rest", () => {
    const buf = 'data: {"type":"text_delta","data":{"text":"hi"}}\n\ndata: {"type":"final","data":{"text":"ok"}}\n\ndata: {"type":"text';
    const { events, rest } = parseSseFrames(buf);
    expect(events).toHaveLength(2);
    expect(events[0].type).toBe("text_delta");
    expect(events[1].type).toBe("final");
    expect(rest).toBe('data: {"type":"text');
  });

  it("ignores malformed json frames", () => {
    const { events } = parseSseFrames("data: not-json\n\n");
    expect(events).toHaveLength(0);
  });

  it("handles empty buffer", () => {
    const { events, rest } = parseSseFrames("");
    expect(events).toHaveLength(0);
    expect(rest).toBe("");
  });
});
```

`desktop/src/lib/diff.test.ts`:
```ts
import { describe, it, expect } from "vitest";
import { parseDiffLines } from "./diff";

describe("parseDiffLines", () => {
  it("classifies add/del/meta/context", () => {
    const lines = parseDiffLines("--- a\n+++ b\n@@ -1 +1 @@\n-old\n+new\n same");
    expect(lines.map((l) => l.kind)).toEqual(["meta", "meta", "meta", "del", "add", "ctx"]);
  });
  it("strips ansi codes", () => {
    const lines = parseDiffLines("\x1b[32m+added\x1b[0m");
    expect(lines[0].kind).toBe("add");
    expect(lines[0].text).toBe("+added");
  });
});
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npm test`
Expected: FAIL（模块不存在）

- [ ] **Step 3: 实现 lib**

`desktop/src/lib/sse.ts`:
```ts
export type AgentEvent = { type: string; data: any };

/** 从缓冲解析完整 SSE 帧（data: {...}\n\n），返回事件 + 剩余半帧 */
export function parseSseFrames(buffer: string): { events: AgentEvent[]; rest: string } {
  const events: AgentEvent[] = [];
  const frames = buffer.split("\n\n");
  const rest = frames.pop() ?? "";
  for (const frame of frames) {
    for (const line of frame.split("\n")) {
      if (!line.startsWith("data:")) continue;
      try {
        events.push(JSON.parse(line.slice(5).trim()));
      } catch {
        /* 坏帧跳过 */
      }
    }
  }
  return { events, rest };
}
```

`desktop/src/lib/diff.ts`:
```ts
export type DiffLine = { kind: "add" | "del" | "meta" | "ctx"; text: string };

const ANSI = /\x1b\[[0-9;]*m/g;

export function parseDiffLines(text: string): DiffLine[] {
  return text.replace(ANSI, "").split("\n").map((line) => {
    if (/^(\+\+\+|---|@@)/.test(line)) return { kind: "meta", text: line };
    if (line.startsWith("+")) return { kind: "add", text: line };
    if (line.startsWith("-")) return { kind: "del", text: line };
    return { kind: "ctx", text: line };
  });
}

export function looksLikeDiff(text: string): boolean {
  const plain = text.replace(ANSI, "");
  return plain.includes("\n+") || plain.includes("\n-");
}
```

`desktop/src/lib/tauri.ts`:
```ts
import { invoke } from "@tauri-apps/api/core";
import { listen, type UnlistenFn } from "@tauri-apps/api/event";

export const getBackendUrl = () => invoke<string>("get_backend_url");
export const getWorkspace = () => invoke<string>("get_workspace");
export const pickWorkspace = () => invoke<string | null>("pick_workspace");
export const restartBackend = () => invoke<void>("restart_backend");

export type BackendEvent =
  | { kind: "ready"; port: number }
  | { kind: "disconnected" }
  | { kind: "error"; message: string };

export async function onBackendEvent(cb: (e: BackendEvent) => void): Promise<UnlistenFn[]> {
  const subs = await Promise.all([
    listen<{ port: number }>("backend://ready", (e) => cb({ kind: "ready", port: e.payload.port })),
    listen("backend://disconnected", () => cb({ kind: "disconnected" })),
    listen<{ message: string }>("backend://error", (e) => cb({ kind: "error", message: e.payload.message })),
  ]);
  return subs;
}
```

`desktop/src/lib/api.ts`:
```ts
import { parseSseFrames, type AgentEvent } from "./sse";

let baseUrl = "";
export const setBaseUrl = (url: string) => { baseUrl = url; };
export const getBaseUrl = () => baseUrl;

async function json<T>(path: string, init?: RequestInit): Promise<T> {
  const resp = await fetch(baseUrl + path, {
    headers: { "Content-Type": "application/json" },
    ...init,
  });
  if (!resp.ok) {
    const err = await resp.json().catch(() => ({}));
    throw new Error((err as any).error || `HTTP ${resp.status}`);
  }
  return resp.json() as Promise<T>;
}

export type Status = {
  provider: string; model: string; workspace: string; permissions: string;
  sessionStore: string; mcp: boolean; version: string; maxIterations: number;
};
export type SessionMeta = { id: string; title: string; createdAt: number; updatedAt: number; messageCount: number };

export const getStatus = () => json<Status>("/status");
export const listSessions = (limit = 50) => json<{ sessions: SessionMeta[] }>(`/sessions?limit=${limit}`);
export const loadSession = (id: string) => json<{ id: string; messages: any[] }>(`/sessions/${encodeURIComponent(id)}`);
export const deleteSession = (id: string) => json<{ ok: boolean }>(`/sessions/${encodeURIComponent(id)}`, { method: "DELETE" });
export const confirm = (id: string, decision: "y" | "n" | "a") =>
  json<{ ok: boolean }>(`/confirm/${encodeURIComponent(id)}`, { method: "POST", body: JSON.stringify({ decision }) });

/** POST /agent → SSE 事件异步迭代器 */
export async function* agentStream(message: string, sessionId: string, signal?: AbortSignal): AsyncGenerator<AgentEvent> {
  const resp = await fetch(baseUrl + "/agent", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ message, session_id: sessionId }),
    signal,
  });
  if (!resp.ok || !resp.body) {
    const err = await resp.json().catch(() => ({}));
    throw new Error((err as any).error || `HTTP ${resp.status}`);
  }
  const reader = resp.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const { events, rest } = parseSseFrames(buffer);
    buffer = rest;
    for (const ev of events) yield ev;
  }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop && npm test`
Expected: PASS（sse + diff）

- [ ] **Step 5: 提交**

```bash
git add desktop/src/lib
git commit -m "feat: 前端 lib 层——api/sse/tauri/diff + SSE 分帧与 diff 解析单测"
```

---

### Task 13: Zustand store + useAgentStream hook

**Files:**
- Create: `desktop/src/store/useAppStore.ts`、`desktop/src/hooks/useAgentStream.ts`
- Test: `desktop/src/store/store.test.ts`

**Interfaces:**
- Consumes: `api.agentStream`、`api.confirm`、类型 `AgentEvent`
- Produces: store 状态与 actions（见下）；`useAgentStream()` 返回 `{ send }`

store 状态：`backendUrl, status, connected, sessions, activeSession, messages, streaming, pendingConfirm, error`。
消息模型：`ChatMessage = { id, role:'user'|'assistant'|'notice'|'error', text, tools: ToolCard[] }`；`ToolCard = { id, name, input, result?, status:'running'|'ok'|'denied'|'error' }`。

- [ ] **Step 1: 写失败测试**

`desktop/src/store/store.test.ts`:
```ts
import { describe, it, expect, beforeEach } from "vitest";
import { useAppStore } from "./useAppStore";

describe("app store", () => {
  beforeEach(() => useAppStore.getState().reset());

  it("appends user message and starts streaming", () => {
    useAppStore.getState().beginTurn("hello");
    const s = useAppStore.getState();
    expect(s.streaming).toBe(true);
    expect(s.messages[0]).toMatchObject({ role: "user", text: "hello" });
    expect(s.messages[1].role).toBe("assistant");
  });

  it("applies text_delta to last assistant message", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "text_delta", data: { text: "abc" } });
    useAppStore.getState().applyEvent({ type: "text_delta", data: { text: "def" } });
    const msgs = useAppStore.getState().messages;
    expect(msgs[msgs.length - 1].text).toBe("abcdef");
  });

  it("sets pendingConfirm on confirm_request", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "confirm_request", data: { id: "cf1", tool: "write_file", input: {}, preview: "d" } });
    expect(useAppStore.getState().pendingConfirm).toMatchObject({ id: "cf1", tool: "write_file" });
  });

  it("tool_result marks tool ok and clears running", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "tool_use_start", data: { toolId: "t1", toolName: "write_file" } });
    useAppStore.getState().applyEvent({ type: "tool_result", data: { toolName: "write_file", result: "OK: 已写入" } });
    const tool = useAppStore.getState().messages.at(-1)!.tools[0];
    expect(tool.status).toBe("ok");
    expect(tool.result).toContain("OK");
  });
});
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npm test`
Expected: FAIL（store 不存在）

- [ ] **Step 3: 实现 store**

`desktop/src/store/useAppStore.ts`:
```ts
import { create } from "zustand";
import type { AgentEvent } from "../lib/sse";
import type { SessionMeta, Status } from "../lib/api";

export type ToolCard = {
  id: string; name: string; input: any; result?: string;
  status: "running" | "ok" | "denied" | "error";
};
export type ChatMessage = {
  id: string; role: "user" | "assistant" | "notice" | "error";
  text: string; tools: ToolCard[];
};
export type PendingConfirm = { id: string; tool: string; input: any; preview: string };

let seq = 0;
const nid = () => `m${++seq}`;

type State = {
  backendUrl: string;
  status: Status | null;
  connected: boolean;
  sessions: SessionMeta[];
  activeSession: string;
  messages: ChatMessage[];
  streaming: boolean;
  pendingConfirm: PendingConfirm | null;
  error: string | null;

  setBackend: (url: string) => void;
  setStatus: (s: Status) => void;
  setConnected: (c: boolean) => void;
  setSessions: (s: SessionMeta[]) => void;
  setActiveSession: (id: string, messages?: ChatMessage[]) => void;
  setError: (e: string | null) => void;
  beginTurn: (text: string) => void;
  applyEvent: (ev: AgentEvent) => void;
  setPendingConfirm: (c: PendingConfirm | null) => void;
  endTurn: () => void;
  reset: () => void;
};

const lastAssistant = (messages: ChatMessage[]): ChatMessage | undefined =>
  [...messages].reverse().find((m) => m.role === "assistant");

export const useAppStore = create<State>((set, get) => ({
  backendUrl: "",
  status: null,
  connected: false,
  sessions: [],
  activeSession: "",
  messages: [],
  streaming: false,
  pendingConfirm: null,
  error: null,

  setBackend: (url) => set({ backendUrl: url }),
  setStatus: (s) => set({ status: s }),
  setConnected: (c) => set({ connected: c }),
  setSessions: (s) => set({ sessions: s }),
  setActiveSession: (id, messages = []) => set({ activeSession: id, messages, pendingConfirm: null, error: null }),
  setError: (e) => set({ error: e }),

  beginTurn: (text) =>
    set((st) => ({
      streaming: true,
      error: null,
      messages: [
        ...st.messages,
        { id: nid(), role: "user", text, tools: [] },
        { id: nid(), role: "assistant", text: "", tools: [] },
      ],
    })),

  applyEvent: (ev) => {
    const d = ev.data ?? {};
    const st = get();
    const msgs = st.messages.slice();
    const assistant = lastAssistant(msgs);
    switch (ev.type) {
      case "text_delta":
      case "thinking_delta":
        if (assistant) assistant.text += d.text ?? "";
        break;
      case "tool_use_start":
        if (assistant) assistant.tools.push({ id: d.toolId, name: d.toolName, input: {}, status: "running" });
        break;
      case "tool_use_end":
        if (assistant) {
          const t = assistant.tools.find((x) => x.id === d.toolId);
          if (t) t.input = d.input ?? {};
        }
        break;
      case "tool_result": {
        if (assistant) {
          const t = assistant.tools.find((x) => x.name === d.toolName && x.status === "running") ?? assistant.tools.at(-1);
          if (t) {
            t.result = String(d.result ?? "");
            t.status = t.result.startsWith("DENIED:") ? "denied" : t.result.startsWith("ERROR:") ? "error" : "ok";
          }
        }
        break;
      }
      case "confirm_request":
        set({ pendingConfirm: { id: d.id, tool: d.tool, input: d.input ?? {}, preview: d.preview ?? "" } });
        return;
      case "notice":
        msgs.push({ id: nid(), role: "notice", text: d.text ?? "", tools: [] });
        break;
      case "error":
        msgs.push({ id: nid(), role: "error", text: d.message ?? "未知错误", tools: [] });
        break;
      case "final":
        if (assistant && !assistant.text) assistant.text = d.text ?? "";
        break;
    }
    set({ messages: msgs });
  },

  setPendingConfirm: (c) => set({ pendingConfirm: c }),
  endTurn: () => set({ streaming: false, pendingConfirm: null }),

  reset: () =>
    set({
      backendUrl: "", status: null, connected: false, sessions: [], activeSession: "",
      messages: [], streaming: false, pendingConfirm: null, error: null,
    }),
}));
```

- [ ] **Step 4: 实现 useAgentStream**

`desktop/src/hooks/useAgentStream.ts`:
```ts
import { useRef, useCallback } from "react";
import { agentStream, confirm as apiConfirm } from "../lib/api";
import { useAppStore } from "../store/useAppStore";

export function useAgentStream() {
  const abortRef = useRef<AbortController | null>(null);
  const store = useAppStore;

  const send = useCallback(async (text: string) => {
    const st = store.getState();
    if (st.streaming || !text.trim()) return;
    const sessionId = st.activeSession || `web-${Date.now().toString(36)}`;
    if (!st.activeSession) store.getState().setActiveSession(sessionId);
    store.getState().beginTurn(text);
    const ac = new AbortController();
    abortRef.current = ac;
    try {
      for await (const ev of agentStream(text, sessionId, ac.signal)) {
        store.getState().applyEvent(ev);
      }
    } catch (e: any) {
      if (e?.name !== "AbortError") store.getState().setError(e?.message ?? String(e));
    } finally {
      store.getState().endTurn();
      abortRef.current = null;
    }
  }, []);

  const answerConfirm = useCallback(async (decision: "y" | "n" | "a") => {
    const c = store.getState().pendingConfirm;
    if (!c) return;
    store.getState().setPendingConfirm(null);
    try {
      await apiConfirm(c.id, decision);
    } catch (e: any) {
      store.getState().setError(e?.message ?? String(e));
    }
  }, []);

  const abort = useCallback(() => abortRef.current?.abort(), []);

  return { send, answerConfirm, abort };
}
```

- [ ] **Step 5: 运行确认通过 + 提交**

Run: `cd desktop && npm test`
Expected: PASS（store 4 条 + 之前）

```bash
git add desktop/src/store desktop/src/hooks
git commit -m "feat: Zustand store + useAgentStream——事件归约/授权应答/流中止"
```

---

### Task 14: 布局 — AppShell / TitleBar / Sidebar / StatusBar

**Files:**
- Create: `desktop/src/components/layout/{AppShell,TitleBar,Sidebar,StatusBar}.tsx`
- Modify: `desktop/src/App.tsx`

**Interfaces:**
- Consumes: store、`tauri.pickWorkspace/getWorkspace`、shadcn button/tooltip/scroll-area
- Produces: 两栏布局骨架（左会话侧栏 + 主区），顶标题栏 + 底状态栏

- [ ] **Step 1: 实现 TitleBar.tsx**

```tsx
import { useAppStore } from "../../store/useAppStore";
import { Badge } from "../ui/badge";
import { Cpu, FolderGit2, ShieldCheck, ShieldOff } from "lucide-react";

export function TitleBar() {
  const status = useAppStore((s) => s.status);
  const connected = useAppStore((s) => s.connected);
  return (
    <header className="flex h-12 shrink-0 items-center gap-3 border-b border-border bg-surface px-4">
      <div className="flex items-center gap-2">
        <span className="grid h-6 w-6 place-items-center rounded-md bg-primary-deep font-mono text-xs font-bold text-white">php</span>
        <span className="text-sm font-semibold tracking-tight text-ink">PHP Agent</span>
      </div>
      <span className="flex items-center gap-1 text-xs text-muted">
        <FolderGit2 size={13} /> {status?.workspace ?? "…"}
      </span>
      <div className="ml-auto flex items-center gap-2">
        {status && (
          <Badge variant="outline" className="gap-1 border-border font-mono text-[11px] text-muted">
            <Cpu size={12} /> {status.provider}/{status.model || "未配置"}
          </Badge>
        )}
        {status && (
          <Badge variant="outline" className="gap-1 border-border text-[11px] text-muted">
            {status.permissions === "ask" ? <ShieldCheck size={12} className="text-success" /> : <ShieldOff size={12} className="text-warning" />}
            {status.permissions === "ask" ? "按需授权" : "全部放行"}
          </Badge>
        )}
        <span className={`h-2 w-2 rounded-full ${connected ? "bg-success" : "bg-danger"}`} title={connected ? "已连接" : "未连接"} />
      </div>
    </header>
  );
}
```

- [ ] **Step 2: 实现 StatusBar.tsx**

```tsx
import { useAppStore } from "../../store/useAppStore";

export function StatusBar() {
  const { status, activeSession, streaming, connected } = useAppStore();
  return (
    <footer className="flex h-7 shrink-0 items-center gap-4 border-t border-border bg-surface px-4 font-mono text-[11px] text-muted">
      <span className={connected ? "text-success" : "text-danger"}>{connected ? "● 已连接" : "○ 未连接"}</span>
      <span>会话 {activeSession || "—"}</span>
      {streaming && <span className="text-info">流式输出中…</span>}
      <span className="ml-auto">v{status?.version ?? "0.2.0"} · {status?.sessionStore ?? ""}</span>
    </footer>
  );
}
```

- [ ] **Step 3: 实现 Sidebar.tsx**（会话列表容器 + workspace 选择 + 新建；SessionList/Item 在 Task 18）

```tsx
import { useAppStore } from "../../store/useAppStore";
import { pickWorkspace } from "../../lib/tauri";
import { Button } from "../ui/button";
import { ScrollArea } from "../ui/scroll-area";
import { FolderOpen, Plus, Search } from "lucide-react";
import { SessionList } from "../sessions/SessionList";
import { useState } from "react";

export function Sidebar({ onNewSession }: { onNewSession: () => void }) {
  const workspace = useAppStore((s) => s.status?.workspace);
  const sessions = useAppStore((s) => s.sessions);
  const [q, setQ] = useState("");

  const filtered = sessions.filter((s) => s.title.toLowerCase().includes(q.toLowerCase()));

  return (
    <aside className="flex w-64 shrink-0 flex-col border-r border-border bg-surface">
      <div className="flex items-center gap-2 border-b border-border p-3">
        <Button variant="outline" size="sm" className="flex-1 justify-start gap-2 border-border text-muted" onClick={() => pickWorkspace()}>
          <FolderOpen size={14} /> <span className="truncate">{workspace ? workspace.split("/").pop() : "选择项目"}</span>
        </Button>
        <Button size="sm" className="bg-primary-deep text-white hover:bg-primary" onClick={onNewSession} title="新建会话">
          <Plus size={16} />
        </Button>
      </div>
      <div className="flex items-center gap-2 px-3 py-2 text-muted">
        <Search size={14} />
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="搜索会话"
          className="w-full bg-transparent text-xs text-ink outline-none placeholder:text-muted"
        />
      </div>
      <ScrollArea className="flex-1">
        <SessionList sessions={filtered} />
      </ScrollArea>
    </aside>
  );
}
```

- [ ] **Step 4: 实现 AppShell.tsx + 替换 App.tsx**

`AppShell.tsx`:
```tsx
import { TitleBar } from "./TitleBar";
import { Sidebar } from "./Sidebar";
import { StatusBar } from "./StatusBar";
import { ChatView } from "../chat/ChatView";

export function AppShell({ onNewSession }: { onNewSession: () => void }) {
  return (
    <div className="flex h-screen flex-col bg-bg text-ink">
      <TitleBar />
      <div className="flex min-h-0 flex-1">
        <Sidebar onNewSession={onNewSession} />
        <main className="flex min-w-0 flex-1 flex-col">
          <ChatView />
        </main>
      </div>
      <StatusBar />
    </div>
  );
}
```

`App.tsx`（引导：拿 backend url → status/sessions → 渲染；连接事件）:
```tsx
import { useEffect, useState } from "react";
import { AppShell } from "./components/layout/AppShell";
import { ConnectionBanner } from "./components/ConnectionBanner";
import { useAppStore } from "./store/useAppStore";
import { getBackendUrl, onBackendEvent } from "./lib/tauri";
import { getStatus, listSessions, setBaseUrl } from "./lib/api";

export default function App() {
  const [ready, setReady] = useState(false);
  const store = useAppStore;

  async function bootstrap() {
    try {
      const url = await getBackendUrl();
      setBaseUrl(url);
      store.getState().setBackend(url);
      const [status, sessions] = await Promise.all([getStatus(), listSessions()]);
      store.getState().setStatus(status);
      store.getState().setSessions(sessions.sessions);
      store.getState().setConnected(true);
      setReady(true);
    } catch {
      store.getState().setConnected(false);
    }
  }

  useEffect(() => {
    bootstrap();
    const off = onBackendEvent((e) => {
      if (e.kind === "ready") bootstrap();
      else if (e.kind === "disconnected") store.getState().setConnected(false);
      else if (e.kind === "error") store.getState().setError(e.message);
    });
    return () => { off.then((fns) => fns.forEach((f) => f())); };
  }, []);

  function newSession() {
    store.getState().setActiveSession(`web-${Date.now().toString(36)}`, []);
  }

  if (!ready) {
    return (
      <div className="grid h-screen place-items-center bg-bg text-muted">
        <div className="animate-pulse font-mono text-sm">正在连接 PHP 内核…</div>
      </div>
    );
  }

  return (
    <>
      <ConnectionBanner onRetry={bootstrap} />
      <AppShell onNewSession={newSession} />
    </>
  );
}
```

- [ ] **Step 5: 验证构建 + 提交**（ChatView/SessionList/ConnectionBanner 在后续任务建；本步先建最小占位以通过编译）

先建占位：`ChatView.tsx` 返回 `<div className="flex-1" />`；`SessionList.tsx` 返回 `<div />`；`ConnectionBanner.tsx` 返回 `null`（Task 15/18/19 替换）。

Run: `cd desktop && npm run build`
Expected: 通过。

```bash
git add desktop/src
git commit -m "feat: 布局骨架——TitleBar/Sidebar/StatusBar/AppShell + 引导连接"
```

---

### Task 15: 聊天 — ChatView / MessageBubble / Markdown / Composer

**Files:**
- Create: `desktop/src/components/chat/{ChatView,MessageBubble,Markdown,Composer}.tsx`
- Replace: 占位 ChatView

**Interfaces:**
- Consumes: store、`useAgentStream`、react-markdown/remark-gfm、ToolCallCard（Task 16）
- Produces: 流式聊天主区 + 输入框（Enter 发送/Shift+Enter 换行，流式禁用）

- [ ] **Step 1: 实现 Markdown.tsx**

```tsx
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

export function Markdown({ text }: { text: string }) {
  return (
    <div className="prose-agent text-sm leading-relaxed">
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        components={{
          code({ className, children, ...props }) {
            const isBlock = /language-/.test(className ?? "");
            return isBlock ? (
              <pre className="my-2 overflow-x-auto rounded-lg border border-border bg-bg p-3 font-mono text-[12.5px]">
                <code className={className} {...props}>{children}</code>
              </pre>
            ) : (
              <code className="rounded bg-elevated px-1.5 py-0.5 font-mono text-[12.5px] text-primary" {...props}>{children}</code>
            );
          },
          a({ children, ...props }) {
            return <a className="text-info underline-offset-2 hover:underline" target="_blank" rel="noreferrer" {...props}>{children}</a>;
          },
        }}
      >
        {text}
      </ReactMarkdown>
    </div>
  );
}
```

在 index.css 追加 `.prose-agent` 基础排版（p  margins、list、heading 色）:
```css
.prose-agent p { margin: 0.4em 0; }
.prose-agent ul { list-style: disc; padding-left: 1.4em; margin: 0.4em 0; }
.prose-agent ol { list-style: decimal; padding-left: 1.4em; margin: 0.4em 0; }
.prose-agent h1, .prose-agent h2, .prose-agent h3 { color: var(--text); font-weight: 600; margin: 0.6em 0 0.3em; }
.prose-agent blockquote { border-left: 3px solid var(--border); padding-left: 0.8em; color: var(--muted); }
.prose-agent table { border-collapse: collapse; }
.prose-agent th, .prose-agent td { border: 1px solid var(--border); padding: 4px 8px; }
```

- [ ] **Step 2: 实现 MessageBubble.tsx**

```tsx
import type { ChatMessage } from "../../store/useAppStore";
import { Markdown } from "./Markdown";
import { ToolCallCard } from "./ToolCallCard";
import { cn } from "../../lib/utils";

export function MessageBubble({ msg, streaming }: { msg: ChatMessage; streaming?: boolean }) {
  if (msg.role === "notice") {
    return <div className="animate-fade-up self-start rounded-lg border border-dashed border-border px-3 py-2 text-xs text-info">ℹ {msg.text}</div>;
  }
  if (msg.role === "error") {
    return <div className="animate-fade-up self-start rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger">✗ {msg.text}</div>;
  }
  if (msg.role === "user") {
    return (
      <div className="animate-fade-up max-w-[80%] self-end whitespace-pre-wrap rounded-2xl rounded-br-sm border border-primary/30 bg-primary/15 px-4 py-2.5 text-sm text-ink">
        {msg.text}
      </div>
    );
  }
  return (
    <div className="animate-fade-up w-full max-w-[860px] self-start rounded-2xl rounded-bl-sm border border-border bg-surface px-4 py-3">
      {msg.tools.map((t) => <ToolCallCard key={t.id} tool={t} />)}
      {msg.text && <Markdown text={msg.text} />}
      {streaming && !msg.text && msg.tools.length === 0 && <span className="inline-block h-4 w-2 animate-blink bg-info align-middle" />}
      {streaming && msg.text && <span className="ml-0.5 inline-block h-4 w-2 animate-blink bg-info align-middle" />}
    </div>
  );
}
```

- [ ] **Step 3: 实现 Composer.tsx**

```tsx
import { useState, useRef, type KeyboardEvent } from "react";
import { useAppStore } from "../../store/useAppStore";
import { Button } from "../ui/button";
import { SendHorizonal, Square } from "lucide-react";

export function Composer({ onSend, onAbort }: { onSend: (t: string) => void; onAbort: () => void }) {
  const streaming = useAppStore((s) => s.streaming);
  const [text, setText] = useState("");
  const ref = useRef<HTMLTextAreaElement>(null);

  function submit() {
    const t = text.trim();
    if (!t || streaming) return;
    onSend(t);
    setText("");
  }
  function onKey(e: KeyboardEvent) {
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); submit(); }
  }

  return (
    <div className="shrink-0 border-t border-border bg-surface p-3">
      <div className="mx-auto flex max-w-[860px] items-end gap-2">
        <textarea
          ref={ref}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={onKey}
          rows={1}
          placeholder="输入任务…（Enter 发送，Shift+Enter 换行）"
          className="max-h-40 flex-1 resize-none rounded-xl border border-border bg-bg px-4 py-3 text-sm text-ink outline-none transition-colors placeholder:text-muted focus:border-primary"
        />
        {streaming ? (
          <Button variant="outline" size="icon" className="h-11 w-11 border-danger/40 text-danger" onClick={onAbort} title="停止">
            <Square size={16} />
          </Button>
        ) : (
          <Button size="icon" className="h-11 w-11 bg-primary-deep text-white hover:bg-primary" onClick={submit} disabled={!text.trim()} title="发送">
            <SendHorizonal size={18} />
          </Button>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: 实现 ChatView.tsx（替换占位）**

```tsx
import { useEffect, useRef } from "react";
import { useAppStore } from "../../store/useAppStore";
import { useAgentStream } from "../../hooks/useAgentStream";
import { MessageBubble } from "./MessageBubble";
import { Composer } from "./Composer";
import { Welcome } from "../Welcome";
import { ConfirmDialog } from "../confirm/ConfirmDialog";

export function ChatView() {
  const messages = useAppStore((s) => s.messages);
  const streaming = useAppStore((s) => s.streaming);
  const { send, answerConfirm, abort } = useAgentStream();
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: "smooth" }); }, [messages, streaming]);

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="flex-1 overflow-y-auto px-6 py-5">
        <div className="mx-auto flex max-w-[860px] flex-col gap-4">
          {messages.length === 0 ? (
            <Welcome onPick={send} />
          ) : (
            messages.map((m, i) => (
              <MessageBubble key={m.id} msg={m} streaming={streaming && i === messages.length - 1 && m.role === "assistant"} />
            ))
          )}
          <div ref={endRef} />
        </div>
      </div>
      <Composer onSend={send} onAbort={abort} />
      <ConfirmDialog onAnswer={answerConfirm} />
    </div>
  );
}
```

- [ ] **Step 5: 验证构建 + 提交**（Welcome/ConfirmDialog/ToolCallCard 后续任务建；先建最小占位通过编译）

占位：`Welcome.tsx` 返回 `null`；`ConfirmDialog.tsx` 返回 `null`；`ToolCallCard.tsx` 返回 `null`。

Run: `cd desktop && npm run build`
Expected: 通过。

```bash
git add desktop/src
git commit -m "feat: 聊天主区——流式 MessageBubble/Markdown 渲染/Composer 输入"
```

---

### Task 16: ToolCallCard + DiffView

**Files:**
- Create: `desktop/src/components/chat/{ToolCallCard,DiffView}.tsx`（替换占位）

**Interfaces:**
- Consumes: `diff.parseDiffLines/looksLikeDiff`、store ToolCard 类型、shadcn badge
- Produces: 工具卡（状态色左边框 + 展开折叠 + 参数 + 结果/diff）

- [ ] **Step 1: 实现 DiffView.tsx**

```tsx
import { parseDiffLines } from "../../lib/diff";
import { cn } from "../../lib/utils";

export function DiffView({ text }: { text: string }) {
  const lines = parseDiffLines(text);
  return (
    <pre className="overflow-x-auto rounded-lg border border-border bg-bg p-3 font-mono text-[12px] leading-relaxed">
      {lines.map((l, i) => (
        <div
          key={i}
          className={cn(
            "whitespace-pre",
            l.kind === "add" && "bg-success/10 text-success",
            l.kind === "del" && "bg-danger/10 text-danger",
            l.kind === "meta" && "text-muted",
            l.kind === "ctx" && "text-ink/80"
          )}
        >
          {l.text || " "}
        </div>
      ))}
    </pre>
  );
}
```

- [ ] **Step 2: 实现 ToolCallCard.tsx**

```tsx
import { useState } from "react";
import type { ToolCard } from "../../store/useAppStore";
import { DiffView } from "./DiffView";
import { looksLikeDiff } from "../../lib/diff";
import { cn } from "../../lib/utils";
import { ChevronRight, Wrench, Loader2, Check, X, AlertTriangle } from "lucide-react";

const STATUS = {
  running: { border: "border-l-warning", icon: <Loader2 size={13} className="animate-spin text-warning" />, label: "运行中" },
  ok: { border: "border-l-success", icon: <Check size={13} className="text-success" />, label: "完成" },
  denied: { border: "border-l-danger", icon: <X size={13} className="text-danger" />, label: "已拒绝" },
  error: { border: "border-l-danger", icon: <AlertTriangle size={13} className="text-danger" />, label: "错误" },
} as const;

export function ToolCallCard({ tool }: { tool: ToolCard }) {
  const [open, setOpen] = useState(false);
  const s = STATUS[tool.status];
  const args = JSON.stringify(tool.input ?? {});
  const result = tool.result ?? "";
  const isDiff = looksLikeDiff(result);

  return (
    <div className={cn("my-2 rounded-lg border border-border border-l-[3px] bg-elevated/60", s.border)}>
      <button onClick={() => setOpen((o) => !o)} className="flex w-full items-center gap-2 px-3 py-2 text-left">
        <ChevronRight size={14} className={cn("text-muted transition-transform", open && "rotate-90")} />
        <Wrench size={13} className="text-warning" />
        <span className="font-mono text-[12.5px] font-semibold text-ink">{tool.name}</span>
        <span className="truncate font-mono text-[11px] text-muted">{args.slice(0, 80)}</span>
        <span className="ml-auto flex items-center gap-1 text-[11px] text-muted">{s.icon} {s.label}</span>
      </button>
      {open && (
        <div className="space-y-2 border-t border-border px-3 py-2">
          <div>
            <div className="mb-1 text-[10px] uppercase tracking-wide text-muted">参数</div>
            <pre className="overflow-x-auto rounded bg-bg p-2 font-mono text-[11.5px] text-ink/90">{JSON.stringify(tool.input ?? {}, null, 2)}</pre>
          </div>
          {result && (
            <div>
              <div className="mb-1 text-[10px] uppercase tracking-wide text-muted">结果</div>
              {isDiff ? <DiffView text={result} /> : (
                <pre className={cn("overflow-x-auto rounded bg-bg p-2 font-mono text-[11.5px]", tool.status === "error" || tool.status === "denied" ? "text-danger" : "text-success")}>
                  {result.slice(0, 4000)}
                </pre>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 3: 验证构建**

Run: `cd desktop && npm run build`
Expected: 通过。

- [ ] **Step 4: 手动验证**（fake provider 演示触发 write_file/run_command，工具卡状态色与 diff 上色正确）

Run: `cd desktop && npm run tauri dev`，发送「演示」观察。

- [ ] **Step 5: 提交**

```bash
git add desktop/src/components/chat
git commit -m "feat: 工具调用卡片——状态色边框/展开折叠/diff 按行上色"
```

---

### Task 17: ConfirmDialog（授权弹窗 + 键盘）

**Files:**
- Create: `desktop/src/components/confirm/ConfirmDialog.tsx`（替换占位）
- Test: `desktop/src/components/confirm/ConfirmDialog.test.tsx`

**Interfaces:**
- Consumes: store.pendingConfirm、shadcn dialog/button、DiffView、`onAnswer(y|n|a)`
- Produces: 模态授权弹窗（工具名 + 参数 + diff 预览 + 允许/拒绝/总是允许；键盘 Y/N/A/Esc）

- [ ] **Step 1: 写失败测试**

`ConfirmDialog.test.tsx`:
```tsx
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { ConfirmDialog } from "./ConfirmDialog";
import { useAppStore } from "../../store/useAppStore";

describe("ConfirmDialog", () => {
  beforeEach(() => {
    useAppStore.getState().reset();
    useAppStore.getState().setPendingConfirm({ id: "cf1", tool: "write_file", input: { path: "a.php" }, preview: "+new" });
  });

  it("renders tool and preview", () => {
    render(<ConfirmDialog onAnswer={() => {}} />);
    expect(screen.getByText("write_file")).toBeInTheDocument();
  });

  it("keyboard Y answers yes", () => {
    const onAnswer = vi.fn();
    render(<ConfirmDialog onAnswer={onAnswer} />);
    fireEvent.keyDown(window, { key: "y" });
    expect(onAnswer).toHaveBeenCalledWith("y");
  });

  it("Escape answers no", () => {
    const onAnswer = vi.fn();
    render(<ConfirmDialog onAnswer={onAnswer} />);
    fireEvent.keyDown(window, { key: "Escape" });
    expect(onAnswer).toHaveBeenCalledWith("n");
  });
});
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npm test`
Expected: FAIL（占位组件无渲染/键盘）

- [ ] **Step 3: 实现 ConfirmDialog.tsx**

```tsx
import { useEffect } from "react";
import { useAppStore } from "../../store/useAppStore";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "../ui/dialog";
import { Button } from "../ui/button";
import { DiffView } from "../chat/DiffView";
import { ShieldAlert } from "lucide-react";

export function ConfirmDialog({ onAnswer }: { onAnswer: (d: "y" | "n" | "a") => void }) {
  const pending = useAppStore((s) => s.pendingConfirm);

  useEffect(() => {
    if (!pending) return;
    function onKey(e: KeyboardEvent) {
      const k = e.key.toLowerCase();
      if (k === "y") onAnswer("y");
      else if (k === "n" || k === "escape") onAnswer("n");
      else if (k === "a") onAnswer("a");
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [pending, onAnswer]);

  if (!pending) return null;
  const isWrite = pending.tool === "write_file";

  return (
    <Dialog open onOpenChange={(o) => { if (!o) onAnswer("n"); }}>
      <DialogContent className="max-w-2xl border-border bg-elevated text-ink">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-ink">
            <ShieldAlert size={18} className="text-warning" /> 工具授权请求
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-3">
          <div className="flex items-center gap-2">
            <span className="rounded bg-primary/15 px-2 py-0.5 font-mono text-sm text-primary">{pending.tool}</span>
            {isWrite && <span className="font-mono text-xs text-muted">{pending.input?.path}</span>}
          </div>
          {pending.preview ? (
            pending.preview.startsWith("新文件") || pending.preview.startsWith("(") ? (
              <div className="rounded-lg border border-border bg-bg p-3 text-sm text-muted">{pending.preview}</div>
            ) : (
              <DiffView text={pending.preview} />
            )
          ) : (
            <pre className="overflow-x-auto rounded-lg border border-border bg-bg p-3 font-mono text-[12px] text-ink/90">
              {JSON.stringify(pending.input, null, 2)}
            </pre>
          )}
          <p className="text-xs text-muted">快捷键：Y 允许 · N 拒绝 · A 本次会话总是允许 · Esc 拒绝</p>
        </div>
        <DialogFooter className="gap-2">
          <Button variant="outline" className="border-danger/40 text-danger hover:bg-danger/10" onClick={() => onAnswer("n")}>拒绝 (N)</Button>
          <Button variant="outline" className="border-border text-muted" onClick={() => onAnswer("a")}>总是允许 (A)</Button>
          <Button className="bg-primary-deep text-white hover:bg-primary" onClick={() => onAnswer("y")}>允许 (Y)</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop && npm test`
Expected: PASS（ConfirmDialog 3 条）

- [ ] **Step 5: 提交**

```bash
git add desktop/src/components/confirm
git commit -m "feat: 授权弹窗——diff 预览 + Y/N/A/Esc 键盘 + 测试"
```

---

### Task 18: 会话 — SessionList / SessionItem + Welcome 空态

**Files:**
- Create: `desktop/src/components/sessions/{SessionList,SessionItem}.tsx`（替换占位）、`desktop/src/components/Welcome.tsx`（替换占位）

**Interfaces:**
- Consumes: store、`api.loadSession/deleteSession/listSessions`、shadcn dropdown-menu/button
- Produces: 会话列表（切换/删除）、有辨识度的欢迎空态（PHP 象 motif + 快速开始）

- [ ] **Step 1: 实现 SessionItem.tsx**

```tsx
import type { SessionMeta } from "../../lib/api";
import { useAppStore } from "../../store/useAppStore";
import { loadSession, deleteSession, listSessions } from "../../lib/api";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "../ui/dropdown-menu";
import { MoreVertical, Trash2, MessageSquare } from "lucide-react";
import { cn } from "../../lib/utils";

function toChatMessages(messages: any[]) {
  // 把归一化历史转成只读 transcript（user 文本 + assistant 文本/工具）
  const out: any[] = [];
  for (const m of messages) {
    if (m.role === "user" && Array.isArray(m.content)) {
      const text = m.content.filter((b: any) => b.type === "text").map((b: any) => b.text).join("");
      if (text) out.push({ id: `h${out.length}`, role: "user", text, tools: [] });
    } else if (m.role === "assistant" && Array.isArray(m.content)) {
      const text = m.content.filter((b: any) => b.type === "text").map((b: any) => b.text).join("");
      const tools = m.content.filter((b: any) => b.type === "tool_use").map((b: any) => ({ id: b.id, name: b.name, input: b.input, status: "ok" }));
      out.push({ id: `h${out.length}`, role: "assistant", text, tools });
    }
  }
  return out;
}

export function SessionItem({ meta }: { meta: SessionMeta }) {
  const active = useAppStore((s) => s.activeSession) === meta.id;

  async function open() {
    const { messages } = await loadSession(meta.id);
    useAppStore.getState().setActiveSession(meta.id, toChatMessages(messages));
  }
  async function remove() {
    await deleteSession(meta.id);
    const { sessions } = await listSessions();
    useAppStore.getState().setSessions(sessions);
    if (active) useAppStore.getState().setActiveSession("", []);
  }

  return (
    <div className={cn("group mx-2 mb-1 flex items-center gap-2 rounded-lg px-2 py-2 transition-colors", active ? "bg-primary/15" : "hover:bg-elevated")}>
      <button onClick={open} className="flex min-w-0 flex-1 items-center gap-2 text-left">
        <MessageSquare size={14} className={active ? "text-primary" : "text-muted"} />
        <span className={cn("truncate text-xs", active ? "text-ink" : "text-muted")}>{meta.title}</span>
      </button>
      <DropdownMenu>
        <DropdownMenuTrigger className="opacity-0 transition-opacity group-hover:opacity-100">
          <MoreVertical size={14} className="text-muted" />
        </DropdownMenuTrigger>
        <DropdownMenuContent className="border-border bg-elevated">
          <DropdownMenuItem className="text-danger focus:bg-danger/10 focus:text-danger" onClick={remove}>
            <Trash2 size={14} /> 删除会话
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}
```

- [ ] **Step 2: 实现 SessionList.tsx（替换占位）**

```tsx
import type { SessionMeta } from "../../lib/api";
import { SessionItem } from "./SessionItem";

export function SessionList({ sessions }: { sessions: SessionMeta[] }) {
  if (sessions.length === 0) {
    return <div className="px-4 py-6 text-center text-xs text-muted">暂无会话</div>;
  }
  return (
    <div className="py-1">
      {sessions.map((s) => <SessionItem key={s.id} meta={s} />)}
    </div>
  );
}
```

- [ ] **Step 3: 实现 Welcome.tsx（替换占位，有辨识度的空态）**

```tsx
import { Elephant } from "./Elephant";
import { FileCode2, Terminal, FolderSearch } from "lucide-react";

const SUGGESTIONS = [
  { icon: <FolderSearch size={15} />, text: "列出当前目录的文件" },
  { icon: <FileCode2 size={15} />, text: "创建一个 hello.php 并运行验证" },
  { icon: <Terminal size={15} />, text: "用 grep 搜索项目里的 TODO" },
];

export function Welcome({ onPick }: { onPick: (t: string) => void }) {
  return (
    <div className="flex flex-col items-center gap-6 py-16 text-center">
      <Elephant className="h-20 w-20 text-primary" />
      <div>
        <h2 className="text-lg font-semibold tracking-tight text-ink">PHP Agent</h2>
        <p className="mt-1 max-w-sm text-sm text-muted">纯 PHP 的 coding agent。流式对话、工具调用、按需授权——开始一个任务吧。</p>
      </div>
      <div className="flex w-full max-w-md flex-col gap-2">
        {SUGGESTIONS.map((s) => (
          <button
            key={s.text}
            onClick={() => onPick(s.text)}
            className="flex items-center gap-3 rounded-xl border border-border bg-surface px-4 py-3 text-left text-sm text-ink transition-colors hover:border-primary/50 hover:bg-elevated"
          >
            <span className="text-primary">{s.icon}</span> {s.text}
          </button>
        ))}
      </div>
    </div>
  );
}
```

`Elephant.tsx`（PHP 象 motif 的简洁 SVG）:
```tsx
export function Elephant({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" fill="none" className={className} aria-hidden>
      <ellipse cx="32" cy="34" rx="22" ry="18" fill="currentColor" opacity="0.18" />
      <path d="M14 30c0-10 8-18 18-18s18 8 18 18v6c0 3-2 5-5 5h-3l-2 8h-5l-1-6h-6l-1 6h-5l-2-8h-3c-3 0-5-2-5-5v-6z" fill="currentColor" opacity="0.55" />
      <circle cx="25" cy="28" r="2.4" fill="#0B0D12" />
      <path d="M44 34c4 1 7 4 7 8" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
      <text x="32" y="56" textAnchor="middle" fontSize="9" fontFamily="monospace" fill="currentColor">php</text>
    </svg>
  );
}
```

- [ ] **Step 4: 验证构建 + 手动验证会话切换/删除/空态**

Run: `cd desktop && npm run build && npm run tauri dev`（手动）
Expected: 空态显示象 + 建议；发送后会话进侧栏；可切换/删除。

- [ ] **Step 5: 提交**

```bash
git add desktop/src/components
git commit -m "feat: 会话侧栏（切换/删除）+ PHP 象欢迎空态"
```

---

### Task 19: 连接状态 — ConnectionBanner + 断开重连

**Files:**
- Create: `desktop/src/components/ConnectionBanner.tsx`（替换占位）

**Interfaces:**
- Consumes: store.connected/error、`tauri.restartBackend`、`onRetry`
- Produces: 断开/错误横幅 + 重连按钮

- [ ] **Step 1: 实现 ConnectionBanner.tsx**

```tsx
import { useAppStore } from "../store/useAppStore";
import { restartBackend } from "../lib/tauri";
import { Button } from "./ui/button";
import { WifiOff, RefreshCw } from "lucide-react";

export function ConnectionBanner({ onRetry }: { onRetry: () => void }) {
  const connected = useAppStore((s) => s.connected);
  const error = useAppStore((s) => s.error);
  if (connected && !error) return null;

  async function reconnect() {
    useAppStore.getState().setError(null);
    await restartBackend();
    onRetry();
  }

  return (
    <div className="flex items-center gap-3 border-b border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger">
      <WifiOff size={16} />
      <span>{connected ? error : "后端已断开——PHP sidecar 未运行"}</span>
      <Button size="sm" variant="outline" className="ml-auto gap-1 border-danger/40 text-danger hover:bg-danger/10" onClick={reconnect}>
        <RefreshCw size={13} /> 重连
      </Button>
    </div>
  );
}
```

- [ ] **Step 2: 验证构建**

Run: `cd desktop && npm run build`
Expected: 通过。

- [ ] **Step 3: 手动验证断开重连**（`npm run tauri dev` 后 `pkill -f bin/server` → 横幅出现 → 点重连恢复）

- [ ] **Step 4: 提交**

```bash
git add desktop/src/components/ConnectionBanner.tsx
git commit -m "feat: 连接横幅——断开/错误提示 + 一键重连"
```

---

## Phase 4 — 集成验收 + 文档

### Task 20: 端到端验收 + README/.env.example + 图标 + 全量回归

**Files:**
- Modify: `README.md`、`.env.example`
- Create: `desktop/src-tauri/icons/icon.png`（512×512）；恢复 `tauri.conf.json` bundle.active=true

**Interfaces:**
- Consumes: 全部前序任务
- Produces: 通过 spec 验收清单的桌面 MVP；文档同步

- [ ] **Step 1: 全量后端回归**

Run: `vendor/bin/phpunit`
Expected: PASS（原 101 + 新增全部绿）

- [ ] **Step 2: 前端测试 + 构建**

Run: `cd desktop && npm test && npm run build`
Expected: PASS + 构建通过

- [ ] **Step 3: Rust 测试**

Run: `cd desktop/src-tauri && cargo test`
Expected: PASS

- [ ] **Step 4: 端到端验收（fake 离线）**

Run: `cd desktop && AGENT_PROVIDER=fake npm run tauri dev`（手动逐项核对）
验收清单：
- 窗口弹出，自动拉起 sidecar，状态栏显示 provider/版本
- 空态显示 PHP 象 + 建议按钮
- 发送「演示」→ 逐 token 流式 → write_file 弹授权含 diff 预览
- 按 Y → 工具执行 + 结果 diff 上色；按 N → DENIED；按 A → 后续同工具不再询问
- 会话进侧栏，可新建/切换/删除
- 文件夹选择器切换 workspace → sidecar 重启 → 沙箱跟随
- `pkill -f bin/server` → 断开横幅 → 重连恢复
- 退出应用 → `pgrep -fl bin/server` 无孤儿

- [ ] **Step 5: 真实 provider 冒烟**（.env 配 GLM/DeepSeek 等，发送一个真实任务，确认流式 + 工具 + 授权正常）

- [ ] **Step 6: 补图标 + 恢复 bundle**

生成 `desktop/src-tauri/icons/icon.png`（512×512，PHP 靛紫底 + php 字标）；`tauri.conf.json` `bundle.active` 改回 `true`。
Run: `cd desktop/src-tauri && cargo check`
Expected: 通过。

- [ ] **Step 7: 更新 README + .env.example**

README 路线图勾选桌面壳 MVP，新增「桌面应用」章节（`cd desktop && npm install && npm run tauri dev`；说明 dev 用系统 PHP、打包见后续）；测试数更新。
`.env.example` 追加 `AGENT_SERVER_PORT`（0=自动，桌面壳用）与 `AGENT_UI` 说明。

- [ ] **Step 8: 提交**

```bash
git add README.md .env.example desktop
git commit -m "feat: 桌面壳 MVP 收尾——端到端验收 + 图标 + README/.env.example 同步"
```

---

## Self-Review（计划自检）

**1. Spec 覆盖**：
- 架构/进程模型 → Task 8/9/10 ✓
- 端口协商 + AGENT_LISTENING → Task 1/6 ✓
- CORS + /status + 会话端点 → Task 4 ✓
- 授权确认流（ConfirmBroker/confirm_request/POST confirm/超时/断开）→ Task 2/3/5/6 ✓
- DiffPreviewHook server 抑制 → Task 7 ✓
- 前端栈/目录/数据流 → Task 11/12/13 ✓
- 视觉 token/布局/动效/自绘滚动条/空态 → Task 11/14/15/16/18 ✓
- 授权弹窗 + 键盘 → Task 17 ✓
- 连接状态/重连 → Task 19 ✓
- 测试策略（PHPUnit/Rust/Vitest）→ 各任务 TDD + Task 20 ✓
- 验收清单 → Task 20 ✓
- 文档同步 → Task 20 ✓

**2. 占位符扫描**：各代码步均给完整代码；shadcn 原语用 CLI 生成（具体命令，非占位）；占位组件（ChatView/SessionList/Welcome/ConfirmDialog/ToolCallCard/ConnectionBanner）均在后续任务显式替换并标注。无 TBD/TODO。

**3. 类型一致性**：
- `ConfirmBroker` 构造 `(float $timeoutSeconds=120.0, ?string $workspace=null)` 在 Task 3/5/6 一致 ✓
- `AgentServer` 构造 `(loop, store, hooks, publicDir, config, ?broker)` 在 Task 4/6 一致 ✓
- `StreamEvent::confirmRequest(id,tool,input,preview)` Task 2/3 一致 ✓
- store `ToolCard.status` 枚举 running/ok/denied/error 在 Task 13/16 一致 ✓
- `parseSseFrames`/`parseDiffLines`/`looksLikeDiff` 签名 Task 12/15/16/17 一致 ✓
- Tauri commands 名 get_backend_url/get_workspace/pick_workspace/restart_backend 在 Task 10/12 一致 ✓
- events backend://ready|disconnected|error 在 Task 10/12/14 一致 ✓

**已知实现期微调点**（非占位，编译器/运行时为准）：Tauri dialog `blocking_pick_folder` 的 trait 约束（Task 10 已注）；shadcn init 对 index.css/tailwind.config 的改写需按 Task 11 覆盖；图标 dev 期可暂关 bundle。