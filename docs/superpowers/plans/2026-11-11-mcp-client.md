# MCP 客户端实施计划（tools + stdio + Streamable HTTP）

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** agent 启动时连接 `.mcp.json` 声明的 MCP server，把其工具以 `mcp__<server>__<tool>` 挂进 ToolRegistry，LLM 可直接调用。

**Architecture:** 新增 `src/Mcp/` 模块（配置解析 / Transport 接口 / Stdio+HTTP 两个传输 / 协议连接层 / 工具适配器），一个内置 `McpPlugin` 负责装配；只读放行通过新标记接口 `ReadOnlyTool` + ConfirmHook 查询 ToolRegistry 实现。

**Tech Stack:** 纯 PHP 8.1（proc_open、stream_select、curl），PHPUnit 10.5，零新 composer 依赖。

**Spec:** `docs/superpowers/specs/2026-11-11-mcp-client-design.md`

## Global Constraints

- PHP ≥ 8.1；仅用 ext-curl / ext-json / ext-mbstring + 标准 proc_open/stream 函数；**禁止新增 composer 依赖**
- 代码风格对齐现有代码：`declare(strict_types=1);`、`final class`、readonly 提升属性的构造器、中文 docblock
- 测试：命名空间 `PhpAgent\Tests`，PHPUnit 10.5，**不用 mock 框架**（匿名类 / 真实 fixture）
- MCP 协议版本常量：`2025-06-18`；clientInfo：`['name' => 'php-agent', 'version' => '1.0.0']`
- 工具名：`mcp__<server>__<tool>`，server/tool 名只保留 `[A-Za-z0-9_-]`，其余替换 `_`
- 超时：默认 30 秒，`.mcp.json` 每server `timeout` 覆盖
- `AGENT_MCP=off` 整体禁用；`.mcp.json` 缺失时插件静默 no-op
- 工作目录：本计划在 git worktree `/Users/mac/.zcode/worktrees/0a3e/php-agent`、分支 `feature/mcp-client` 上执行
- 所有测试命令都在仓库根目录运行：`vendor/bin/phpunit tests/<Name>Test.php`

---

### Task 1: ReadOnlyTool 接口 + ToolRegistry::find + ConfirmHook 动态只读

**Files:**
- Create: `src/Agent/ReadOnlyTool.php`
- Modify: `src/Agent/ToolRegistry.php`
- Modify: `src/Hook/ConfirmHook.php`
- Test: `tests/ReadOnlyToolTest.php`

**Interfaces:**
- Consumes: 现有 `PhpAgent\Agent\Tool`、`PhpAgent\Hook\ConfirmHook`
- Produces:
  - `PhpAgent\Agent\ReadOnlyTool` 接口：`public function readOnly(): bool;`
  - `ToolRegistry::find(string $name): ?Tool`
  - `ConfirmHook::__construct(\Closure $prompt, ?ToolRegistry $registry = null)`（第二参数可选，向后兼容）

- [ ] **Step 1: 写失败测试**

创建 `tests/ReadOnlyToolTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\ReadOnlyTool;
use PhpAgent\Agent\Tool;
use PhpAgent\Agent\ToolRegistry;
use PhpAgent\Hook\ConfirmHook;
use PhpAgent\Hook\HookVetoException;
use PHPUnit\Framework\TestCase;

/**
 * ReadOnlyTool 标记接口：ConfirmHook 借助 ToolRegistry 自动放行声明只读的工具。
 */
final class ReadOnlyToolTest extends TestCase
{
    /** 同时实现 Tool 与 ReadOnlyTool 的最小夹具 */
    private function makeTool(string $name, bool $readOnly): Tool
    {
        return new class($name, $readOnly) implements Tool, ReadOnlyTool {
            public function __construct(private string $n, private bool $ro) {}
            public function name(): string { return $this->n; }
            public function description(): string { return '测试工具'; }
            public function schema(): array { return ['type' => 'object', 'properties' => []]; }
            public function execute(array $input): string { return 'ok'; }
            public function readOnly(): bool { return $this->ro; }
        };
    }

    public function testFindReturnsRegisteredToolOrNull(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe', true));
        self::assertSame('probe', $registry->find('probe')?->name());
        self::assertNull($registry->find('missing'));
    }

    public function testReadOnlyToolBypassesPrompt(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe_ro', true));
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'y';
        }, $registry);

        $hook->handle('before_tool_call', ['tool' => 'probe_ro', 'input' => []]);
        self::assertSame([], $calls, '只读工具不应触发确认');
    }

    public function testNonReadOnlyToolStillPrompts(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe_rw', false));
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'n';
        }, $registry);

        $this->expectException(HookVetoException::class);
        try {
            $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]);
        } finally {
            self::assertSame(['probe_rw'], $calls);
        }
    }

    public function testAlwaysAnswerAllowsSubsequentCalls(): void
    {
        $registry = (new ToolRegistry())->register($this->makeTool('probe_rw', false));
        $answers = ['n', 'a'];
        $calls = 0;
        $hook = new ConfirmHook(function () use (&$answers, &$calls): string {
            $calls++;
            return array_shift($answers) ?? 'n';
        }, $registry);

        try {
            $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]);
            self::fail('第一次答 n 应当否决');
        } catch (HookVetoException) {
        }
        $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]); // a → 放行并记住
        $hook->handle('before_tool_call', ['tool' => 'probe_rw', 'input' => []]); // 已记住，不再问
        self::assertSame(2, $calls, "'a' 之后不应再确认");
    }

    public function testWithoutRegistryKeepsLegacyBehavior(): void
    {
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'y';
        });

        $hook->handle('before_tool_call', ['tool' => 'read_file', 'input' => []]); // 名单内自动放行
        self::assertSame([], $calls);

        $hook->handle('before_tool_call', ['tool' => 'unknown_tool', 'input' => []]); // 名单外仍确认
        self::assertSame(['unknown_tool'], $calls);
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/ReadOnlyToolTest.php`
Expected: FAIL —— `Class "PhpAgent\Agent\ReadOnlyTool" not found`（或 find 方法不存在）

- [ ] **Step 3: 实现**

创建 `src/Agent/ReadOnlyTool.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Agent;

/**
 * 标记接口：实现者向权限层声明自己是只读工具（ConfirmHook 借助 ToolRegistry 自动放行）。
 * 内置五件套走 ConfirmHook 硬编码名单，无需实现本接口。
 */
interface ReadOnlyTool
{
    public function readOnly(): bool;
}
```

`src/Agent/ToolRegistry.php` 在 `names()` 方法后加：

```php
    public function find(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }
```

`src/Hook/ConfirmHook.php`：文件头 use 区加：

```php
use PhpAgent\Agent\ReadOnlyTool;
use PhpAgent\Agent\ToolRegistry;
```

构造器与 handle() 改为：

```php
    public function __construct(
        private readonly \Closure $prompt,
        private readonly ?ToolRegistry $registry = null,
    ) {
    }
```

handle() 中只读判断一行从：

```php
        if (in_array($tool, self::READ_ONLY, true)) {
            return;
        }
```

改为：

```php
        if (in_array($tool, self::READ_ONLY, true)) {
            return;
        }
        $t = $this->registry?->find($tool);
        if ($t instanceof ReadOnlyTool && $t->readOnly()) {
            return;
        }
```

同时把类 docblock 的规则注释补一行「工具实现 ReadOnlyTool 且 readOnly()=true 时自动放行（MCP 等动态工具用）」。

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/ReadOnlyToolTest.php`
Expected: PASS（5 个测试）

再跑全量回归：`vendor/bin/phpunit`
Expected: 全部 PASS（ConfirmHook 第二参数可选，现有测试不受影响）

- [ ] **Step 5: 提交**

```bash
git add src/Agent/ReadOnlyTool.php src/Agent/ToolRegistry.php src/Hook/ConfirmHook.php tests/ReadOnlyToolTest.php
git commit -m "feat: ReadOnlyTool 标记接口 + ConfirmHook 支持动态只读工具"
```

---

### Task 2: McpConfig + McpServerSpec（.mcp.json 解析）

**Files:**
- Create: `src/Mcp/McpServerSpec.php`
- Create: `src/Mcp/McpConfig.php`
- Test: `tests/McpConfigTest.php`

**Interfaces:**
- Produces:
  - `PhpAgent\Mcp\McpServerSpec`：`__construct(public readonly string $name, public readonly string $type /* 'stdio'|'http' */, public readonly array $config, public readonly float $timeout)`；stdio 时 `$config = ['command'=>string,'args'=>string[],'env'=>array<string,string>]`，http 时 `$config = ['url'=>string,'headers'=>array<string,string>]`
  - `McpConfig::load(string $workspace): array{servers: array<string,McpServerSpec>, errors: array<string,string>}`；文件缺失 → 两组皆空；JSON 损坏 → 抛 `\RuntimeException`

- [ ] **Step 1: 写失败测试**

创建 `tests/McpConfigTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Mcp\McpConfig;
use PHPUnit\Framework\TestCase;

/**
 * .mcp.json 解析：缺失静默、损坏抛异常、stdio/http 形态、非法条目收集、timeout。
 */
final class McpConfigTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/php-agent-mcp-cfg-' . uniqid();
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace . '/.mcp.json');
        @rmdir($this->workspace);
    }

    private function write(string $json): void
    {
        file_put_contents($this->workspace . '/.mcp.json', $json);
    }

    public function testMissingFileIsSilentNoop(): void
    {
        $loaded = McpConfig::load($this->workspace);
        self::assertSame([], $loaded['servers']);
        self::assertSame([], $loaded['errors']);
    }

    public function testCorruptJsonThrows(): void
    {
        $this->write('{ not json');
        $this->expectException(\RuntimeException::class);
        McpConfig::load($this->workspace);
    }

    public function testStdioEntry(): void
    {
        $this->write(json_encode(['mcpServers' => [
            'fs' => ['command' => 'npx', 'args' => ['-y', 'server'], 'env' => ['K' => 'v'], 'timeout' => 5],
        ]]));
        $loaded = McpConfig::load($this->workspace);
        self::assertSame([], $loaded['errors']);
        $spec = $loaded['servers']['fs'];
        self::assertSame('stdio', $spec->type);
        self::assertSame('npx', $spec->config['command']);
        self::assertSame(['-y', 'server'], $spec->config['args']);
        self::assertSame(['K' => 'v'], $spec->config['env']);
        self::assertSame(5.0, $spec->timeout);
    }

    public function testHttpEntryWithDefaultTimeout(): void
    {
        $this->write(json_encode(['mcpServers' => [
            'remote' => ['url' => 'https://mcp.example.com/mcp', 'headers' => ['Authorization' => 'Bearer x']],
        ]]));
        $spec = McpConfig::load($this->workspace)['servers']['remote'];
        self::assertSame('http', $spec->type);
        self::assertSame('https://mcp.example.com/mcp', $spec->config['url']);
        self::assertSame(['Authorization' => 'Bearer x'], $spec->config['headers']);
        self::assertSame(30.0, $spec->timeout, '默认 30 秒');
    }

    public function testInvalidEntriesCollectedAsErrors(): void
    {
        $this->write(json_encode(['mcpServers' => [
            'both' => ['command' => 'x', 'url' => 'https://a'],
            'neither' => ['timeout' => 5],
            'good' => ['command' => 'php'],
        ]]));
        $loaded = McpConfig::load($this->workspace);
        self::assertSame(['command 与 url 必须二选一', 'command 与 url 必须二选一'], array_values($loaded['errors']));
        self::assertArrayHasKey('good', $loaded['servers']);
        self::assertArrayNotHasKey('both', $loaded['servers']);
        self::assertArrayNotHasKey('neither', $loaded['servers']);
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/McpConfigTest.php`
Expected: FAIL —— `Class "PhpAgent\Mcp\McpConfig" not found`

- [ ] **Step 3: 实现**

创建 `src/Mcp/McpServerSpec.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * 单个 MCP server 的连接描述（.mcp.json 条目解析结果）。
 * type: 'stdio'（本地子进程）| 'http'（Streamable HTTP）。
 */
final class McpServerSpec
{
    /**
     * @param array $config stdio: ['command'=>string,'args'=>string[],'env'=>array<string,string>]
     *                      http:  ['url'=>string,'headers'=>array<string,string>]
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $config,
        public readonly float $timeout,
    ) {
    }
}
```

创建 `src/Mcp/McpConfig.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * 加载工作区根目录的 .mcp.json（格式与 Claude Code / Cursor 兼容）。
 *
 * 错误语义：文件缺失 = 静默 no-op（大多数用户不配 MCP）；
 * JSON 损坏 = 抛异常（由 McpPlugin 统一警告）；单条目非法 = 收进 errors 跳过该条。
 */
final class McpConfig
{
    private const DEFAULT_TIMEOUT = 30.0;

    /** @return array{servers: array<string, McpServerSpec>, errors: array<string, string>} */
    public static function load(string $workspace): array
    {
        $file = rtrim($workspace, '/') . '/.mcp.json';
        if (!is_file($file)) {
            return ['servers' => [], 'errors' => []];
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("{$file} 不是合法 JSON");
        }

        $servers = [];
        $errors = [];
        foreach ($decoded['mcpServers'] ?? [] as $name => $entry) {
            $name = (string) $name;
            if (!is_array($entry)) {
                $errors[$name] = '条目必须是对象';
                continue;
            }
            $hasCommand = isset($entry['command']) && is_string($entry['command']) && $entry['command'] !== '';
            $hasUrl = isset($entry['url']) && is_string($entry['url']) && $entry['url'] !== '';
            if ($hasCommand === $hasUrl) {
                $errors[$name] = 'command 与 url 必须二选一';
                continue;
            }
            if ($hasCommand) {
                $args = [];
                foreach ((array) ($entry['args'] ?? []) as $a) {
                    $args[] = (string) $a;
                }
                $env = [];
                foreach ($entry['env'] ?? [] as $k => $v) {
                    if (is_string($k)) {
                        $env[$k] = (string) $v;
                    }
                }
                $config = ['command' => $entry['command'], 'args' => $args, 'env' => $env];
                $type = 'stdio';
            } else {
                $headers = [];
                foreach ($entry['headers'] ?? [] as $k => $v) {
                    if (is_string($k)) {
                        $headers[$k] = (string) $v;
                    }
                }
                $config = ['url' => $entry['url'], 'headers' => $headers];
                $type = 'http';
            }
            $timeout = isset($entry['timeout']) && is_numeric($entry['timeout'])
                ? max(1.0, (float) $entry['timeout'])
                : self::DEFAULT_TIMEOUT;
            $servers[$name] = new McpServerSpec($name, $type, $config, $timeout);
        }
        return ['servers' => $servers, 'errors' => $errors];
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/McpConfigTest.php`
Expected: PASS（5 个测试）

- [ ] **Step 5: 提交**

```bash
git add src/Mcp/McpServerSpec.php src/Mcp/McpConfig.php tests/McpConfigTest.php
git commit -m "feat: McpConfig 解析 .mcp.json（stdio/http 二选一，条目级错误收集）"
```

---

### Task 3: Transport 接口 + StdioTransport（proc_open 行式 JSON-RPC）

**Files:**
- Create: `src/Mcp/Transport.php`
- Create: `src/Mcp/StdioTransport.php`
- Create: `tests/fixtures/mcp/fake-stdio-server.php`
- Test: `tests/StdioTransportTest.php`

**Interfaces:**
- Produces:
  - `PhpAgent\Mcp\Transport` 接口：
    - `public function request(array $payload, float $timeout): array;` —— payload 须含 `id`；阻塞等待 id 匹配的响应并原样返回
    - `public function notify(array $payload): void;` —— 单向通知，不等响应
    - `public function setProtocolVersion(string $version): void;` —— initialize 应答后由连接层回填；stdio 为 no-op
    - `public function close(): void;`
  - `StdioTransport::__construct(string $command, array $args = [], array $env = [])`
  - `StdioTransport::recentNotifications(): array`（诊断用）

- [ ] **Step 1: 写 fixture**

创建 `tests/fixtures/mcp/fake-stdio-server.php`（最小 MCP stdio server，讲真协议）：

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 测试夹具：最小 MCP stdio server。行式 JSON-RPC。
 * 提供 echo / envdump / fail / ping(只读注解) / touch(写注解) 工具；
 * initialize 应答后主动发一条 notification，用于测试客户端的通知容忍。
 */

fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']) . "\n");
fflush(STDOUT);

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        continue;
    }
    $resp = ['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null];
    switch ($msg['method']) {
        case 'initialize':
            $resp['result'] = [
                'protocolVersion' => $msg['params']['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'fake-stdio', 'version' => '0.0.1'],
            ];
            break;
        case 'tools/list':
            $resp['result'] = ['tools' => [
                ['name' => 'echo', 'description' => '回显输入文本',
                 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
                ['name' => 'envdump', 'description' => '返回环境变量 MCP_TEST_VAR',
                 'inputSchema' => ['type' => 'object', 'properties' => []]],
                ['name' => 'fail', 'description' => '总是返回 isError',
                 'inputSchema' => ['type' => 'object', 'properties' => []]],
                ['name' => 'ping', 'description' => '只读探测',
                 'inputSchema' => ['type' => 'object', 'properties' => []],
                 'annotations' => ['readOnlyHint' => true]],
                ['name' => 'touch', 'description' => '模拟写操作',
                 'inputSchema' => ['type' => 'object', 'properties' => []],
                 'annotations' => ['readOnlyHint' => false]],
            ]];
            break;
        case 'tools/call':
            $name = (string) ($msg['params']['name'] ?? '');
            $args = is_array($msg['params']['arguments'] ?? null) ? $msg['params']['arguments'] : [];
            if ($name === 'echo') {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => 'echo: ' . (string) ($args['text'] ?? '')]]];
            } elseif ($name === 'envdump') {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => 'env:' . (string) getenv('MCP_TEST_VAR')]]];
            } elseif ($name === 'fail') {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => '夹具故意失败']], 'isError' => true];
            } else {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => 'ok ' . $name]]];
            }
            break;
        default:
            $resp['error'] = ['code' => -32601, 'message' => "method not found: {$msg['method']}"];
    }
    fwrite(STDOUT, json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
    fflush(STDOUT);
}
```

- [ ] **Step 2: 写失败测试**

创建 `tests/StdioTransportTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Mcp\StdioTransport;
use PHPUnit\Framework\TestCase;

/**
 * StdioTransport：子进程生命周期、行式 JSON-RPC 收发、通知容忍、超时、env 合并。
 */
final class StdioTransportTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/mcp/fake-stdio-server.php';

    private function makeTransport(array $env = []): StdioTransport
    {
        return new StdioTransport(PHP_BINARY, [self::FIXTURE], $env);
    }

    private function initialize(StdioTransport $t): array
    {
        return $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '0']]], 5);
    }

    public function testInitializeHandshakeIgnoresNotification(): void
    {
        $t = $this->makeTransport();
        try {
            $resp = $this->initialize($t);
            self::assertSame('fake-stdio', $resp['result']['serverInfo']['name']);
            // 夹具在响应前发过一条 notification，recentNotifications 应记录它
            self::assertSame('notifications/tools/list_changed', $t->recentNotifications()[0]['method'] ?? null);
        } finally {
            $t->close();
        }
    }

    public function testToolsCallRoundTrip(): void
    {
        $t = $this->makeTransport();
        try {
            $this->initialize($t);
            $resp = $t->request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                'params' => ['name' => 'echo', 'arguments' => ['text' => '你好 world']], ], 5);
            self::assertSame('echo: 你好 world', $resp['result']['content'][0]['text']);
        } finally {
            $t->close();
        }
    }

    public function testEnvMergedIntoChildProcess(): void
    {
        $t = $this->makeTransport(['MCP_TEST_VAR' => 'abc123']);
        try {
            $this->initialize($t);
            $resp = $t->request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => ['name' => 'envdump', 'arguments' => []]], 5);
            self::assertSame('env:abc123', $resp['result']['content'][0]['text']);
        } finally {
            $t->close();
        }
    }

    public function testTimeoutThrows(): void
    {
        // 永不响应的子进程
        $t = new StdioTransport(PHP_BINARY, ['-r', 'sleep(30);']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('超时');
        try {
            $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], 1);
        } finally {
            $t->close();
        }
    }

    public function testNonexistentCommandThrowsOnRequest(): void
    {
        $t = new StdioTransport('definitely-not-a-real-cmd-xyz');
        $this->expectException(\RuntimeException::class);
        $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], 2);
        $t->close();
    }
}
```

- [ ] **Step 3: 运行确认失败**

Run: `vendor/bin/phpunit tests/StdioTransportTest.php`
Expected: FAIL —— `Class "PhpAgent\Mcp\Transport" not found` / `Class "PhpAgent\Mcp\StdioTransport" not found`

- [ ] **Step 4: 实现**

创建 `src/Mcp/Transport.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * MCP 传输层契约：一条连接的 JSON-RPC 消息收发。
 * 消息即完整的 JSON-RPC 2.0 对象（关联数组）。
 */
interface Transport
{
    /**
     * 发送一条请求（$payload 必须含 'id'），阻塞等待 id 匹配的响应并原样返回。
     * 期间收到的 notification 交给实现方暂存/忽略。失败（超时/连接死）抛 \RuntimeException。
     */
    public function request(array $payload, float $timeout): array;

    /** 发送一条 notification（$payload 不含 'id'），不等待响应。 */
    public function notify(array $payload): void;

    /** initialize 应答后由 McpConnection 回填 server 协议版本；HTTP 传输用于后续请求头，stdio 为 no-op。 */
    public function setProtocolVersion(string $version): void;

    /** 关闭底层连接（stdio 杀子进程）。 */
    public function close(): void;
}
```

创建 `src/Mcp/StdioTransport.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * stdio 传输：MCP server 以子进程运行，stdin/stdout 按行交换 JSON-RPC（规范要求单行消息）。
 * stderr 捕获进缓冲区，失败时随异常输出辅助定位。
 */
final class StdioTransport implements Transport
{
    private const MAX_LINE_BYTES = 10 * 1024 * 1024; // 防失控输出
    private const STDERR_TAIL_BYTES = 8192;

    /** @var resource|null */
    private $process;

    /** @var resource stdin 写端 */
    private $stdin;

    /** @var resource stdout 读端（阻塞） */
    private $stdout;

    /** @var resource stderr 读端（非阻塞，随读随排空防管道写满死锁） */
    private $stderr;

    private string $stderrBuf = '';

    /** @var array<int, array> 最近收到的 notification（诊断用） */
    private array $recentNotifications = [];

    /** @param array<int, string> $args @param array<string, string> $env */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly array $env = [],
    ) {
        // 显式构造子进程环境：PHP 默认 variables_order 不含 E，$_ENV 常为空，
        // 而 proc_open 一旦传 env 就是「整体替换」——必须自己带上 PATH/HOME 等，
        // 否则 npx/uvx 之类在子进程里找不到
        $base = [];
        foreach (['PATH', 'HOME', 'TMPDIR', 'USER', 'SHELL', 'LANG', 'TERM'] as $k) {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                $base[$k] = $v;
            }
        }
        $childEnv = array_merge($base, is_array($_ENV) ? $_ENV : [], $this->env);

        $proc = proc_open(
            [$command, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $childEnv
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException("无法启动 MCP server 进程: {$command}");
        }
        $this->process = $proc;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];
        stream_set_blocking($this->stderr, false);
    }

    public function request(array $payload, float $timeout): array
    {
        if (!array_key_exists('id', $payload)) {
            throw new \LogicException('request() 的 payload 必须含 id');
        }
        $this->sendLine($payload);
        $id = $payload['id'];
        $deadline = microtime(true) + $timeout;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException("MCP server '{$this->command}' 响应超时（{$timeout}s）");
            }
            $read = [$this->stdout, $this->stderr];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, 0, (int) ($remaining * 1_000_000));
            if ($selected === false) {
                throw new \RuntimeException('stream_select 失败');
            }
            if ($selected === 0) {
                continue; // 回顶部检查 deadline
            }
            foreach ($read as $stream) {
                if ($stream === $this->stdout) {
                    $line = stream_get_line($this->stdout, self::MAX_LINE_BYTES, "\n");
                    if ($line === false) {
                        if (!proc_get_status($this->process)['running']) {
                            throw new \RuntimeException("MCP server 进程已退出: {$this->stderrTail()}");
                        }
                        continue;
                    }
                    if ($line === '') {
                        continue;
                    }
                    $msg = json_decode($line, true);
                    if (!is_array($msg)) {
                        continue; // 非法行忽略
                    }
                    if (($msg['id'] ?? null) === $id) {
                        return $msg;
                    }
                    if (!array_key_exists('id', $msg)) {
                        $this->recentNotifications[] = $msg;
                        if (count($this->recentNotifications) > 10) {
                            array_shift($this->recentNotifications);
                        }
                    }
                } elseif ($stream === $this->stderr) {
                    $this->drainStderr();
                }
            }
        }
    }

    public function notify(array $payload): void
    {
        $this->sendLine($payload);
    }

    public function setProtocolVersion(string $version): void
    {
        // stdio 无请求头，协议版本仅由连接层校验，此处 no-op
    }

    public function recentNotifications(): array
    {
        return $this->recentNotifications;
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }
        @fclose($this->stdin);
        @fclose($this->stdout);
        @fclose($this->stderr);
        proc_terminate($this->process);
        proc_close($this->process);
        $this->process = null;
    }

    private function sendLine(array $payload): void
    {
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $written = @fwrite($this->stdin, $line);
        if ($written === false || $written < strlen($line)) {
            throw new \RuntimeException("MCP server '{$this->command}' stdin 写入失败（进程可能已退出）: {$this->stderrTail()}");
        }
    }

    private function drainStderr(): void
    {
        while (true) {
            $chunk = fread($this->stderr, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->stderrBuf .= $chunk;
        }
        if (strlen($this->stderrBuf) > self::STDERR_TAIL_BYTES) {
            $this->stderrBuf = substr($this->stderrBuf, -self::STDERR_TAIL_BYTES);
        }
    }

    private function stderrTail(): string
    {
        $tail = trim(substr($this->stderrBuf, -500));
        return $tail !== '' ? $tail : '(无 stderr 输出)';
    }
}
```

- [ ] **Step 5: 运行确认通过**

Run: `vendor/bin/phpunit tests/StdioTransportTest.php`
Expected: PASS（5 个测试）

- [ ] **Step 6: 提交**

```bash
git add src/Mcp/Transport.php src/Mcp/StdioTransport.php tests/fixtures/mcp/fake-stdio-server.php tests/StdioTransportTest.php
git commit -m "feat: stdio 传输——proc_open 子进程 + 行式 JSON-RPC + 通知暂存"
```

---

### Task 4: HttpTransport（curl Streamable HTTP）

**Files:**
- Create: `src/Mcp/HttpTransport.php`
- Create: `tests/fixtures/mcp/fake-http-server.php`
- Test: `tests/HttpTransportTest.php`

**Interfaces:**
- Consumes: `PhpAgent\Mcp\Transport`
- Produces: `HttpTransport::__construct(string $url, array $headers = [], float $connectTimeout = 15.0)`；initialize 响应里的 `Mcp-Session-Id` 会被捕获并在后续请求回传

- [ ] **Step 1: 写 fixture**

创建 `tests/fixtures/mcp/fake-http-server.php`（Streamable HTTP 夹具，供 `php -S 127.0.0.1:0 <此文件>` 使用）：

```php
<?php

declare(strict_types=1);

/**
 * 测试夹具：最小 MCP Streamable HTTP server。
 * initialize → 下发 Mcp-Session-Id；initialized 通知 → 202；
 * tools/list → application/json；tools/call → text/event-stream（SSE 单事件）；
 * method=boom → HTTP 500；无会话头 → 404。
 */
$body = file_get_contents('php://input');
$msg = json_decode((string) $body, true);
if (!is_array($msg) || !isset($msg['method'])) {
    http_response_code(400);
    exit;
}
$method = (string) $msg['method'];

if ($method === 'initialize') {
    header('Content-Type: application/json');
    header('Mcp-Session-Id: sess-test-123');
    echo json_encode([
        'jsonrpc' => '2.0', 'id' => $msg['id'],
        'result' => [
            'protocolVersion' => $msg['params']['protocolVersion'] ?? '2025-06-18',
            'capabilities' => ['tools' => []],
            'serverInfo' => ['name' => 'fake-http', 'version' => '0.0.1'],
        ],
    ]);
    exit;
}

if (($_SERVER['HTTP_MCP_SESSION_ID'] ?? '') !== 'sess-test-123') {
    http_response_code(404);
    exit;
}
if ($method === 'notifications/initialized') {
    http_response_code(202);
    exit;
}
if ($method === 'boom') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => '夹具故意失败']]);
    exit;
}
if ($method === 'tools/list') {
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0', 'id' => $msg['id'],
        'result' => ['tools' => [
            ['name' => 'weather', 'description' => '查天气',
             'inputSchema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]],
        ]],
    ]);
    exit;
}
if ($method === 'tools/call') {
    header('Content-Type: text/event-stream');
    $city = (string) ($msg['params']['arguments']['city'] ?? '?');
    $payload = json_encode([
        'jsonrpc' => '2.0', 'id' => $msg['id'],
        'result' => ['content' => [['type' => 'text', 'text' => "weather({$city}): 晴 25℃"]]],
    ], JSON_UNESCAPED_UNICODE);
    echo "event: message\ndata: {$payload}\n\n";
    exit;
}
http_response_code(404);
```

- [ ] **Step 2: 写失败测试**

创建 `tests/HttpTransportTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Mcp\HttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * HttpTransport：php -S 起本地 Streamable HTTP 夹具，
 * 覆盖 JSON 响应、SSE 响应、Mcp-Session-Id 回传、202 通知、非 2xx 报错。
 */
final class HttpTransportTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/mcp/fake-http-server.php';

    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    private string $port = '';

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            @fclose($this->serverPipes[0]);
            @fclose($this->serverPipes[1]);
            @fclose($this->serverPipes[2]);
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
    }

    /** 启动 php -S，从 stderr 抓取实际端口 */
    private function startServer(): string
    {
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:0', self::FIXTURE],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            $this->fail('无法启动内置 HTTP 服务');
        }
        $this->server = $proc;
        $this->serverPipes = $pipes;
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[2], 8192);
            if (is_string($chunk) && preg_match('/127\.0\.0\.1:(\d+)/', $chunk, $m)) {
                $this->port = $m[1];
                return $this->port;
            }
            usleep(20000);
        }
        $this->fail('内置 HTTP 服务未在 5 秒内监听');
    }

    private function makeTransport(array $headers = []): HttpTransport
    {
        $port = $this->port !== '' ? $this->port : $this->startServer();
        return new HttpTransport("http://127.0.0.1:{$port}/mcp", $headers);
    }

    private function initialize(HttpTransport $t): array
    {
        return $t->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '0']]], 5);
    }

    public function testInitializeAndJsonResponse(): void
    {
        $t = $this->makeTransport();
        $resp = $this->initialize($t);
        self::assertSame('fake-http', $resp['result']['serverInfo']['name']);
        $t->close();
    }

    public function testNotifyAcceptedWith202(): void
    {
        $t = $this->makeTransport();
        $this->initialize($t);
        $t->notify(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']); // 202，不应抛异常
        $t->close();
    }

    public function testSessionIdCarriedOnSubsequentRequests(): void
    {
        $t = $this->makeTransport();
        $this->initialize($t); // 夹具下发 sess-test-123
        $resp = $t->request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], 5);
        // 夹具对无会话头的请求返回 404——能拿到工具说明会话头已回传
        self::assertSame('weather', $resp['result']['tools'][0]['name']);
        $t->close();
    }

    public function testSseResponseParsed(): void
    {
        $t = $this->makeTransport();
        $this->initialize($t);
        $resp = $t->request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'weather', 'arguments' => ['city' => '杭州']]], 5);
        self::assertSame('weather(杭州): 晴 25℃', $resp['result']['content'][0]['text']);
        $t->close();
    }

    public function testNon2xxThrows(): void
    {
        $t = $this->makeTransport();
        $this->initialize($t);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        try {
            $t->request(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'boom', 'params' => []], 5);
        } finally {
            $t->close();
        }
    }
}
```

- [ ] **Step 3: 运行确认失败**

Run: `vendor/bin/phpunit tests/HttpTransportTest.php`
Expected: FAIL —— `Class "PhpAgent\Mcp\HttpTransport" not found`

- [ ] **Step 4: 实现**

创建 `src/Mcp/HttpTransport.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * Streamable HTTP 传输（MCP 2025-03-26+ 规范）：
 * POST JSON-RPC，响应可能是单条 application/json 或 text/event-stream（SSE），
 * 两者都解析；initialize 后捕获 Mcp-Session-Id 并在后续请求回传。
 * 不做 GET 长连接监听（v1 只消费工具，无 sampling/roots 反调）。
 */
final class HttpTransport implements Transport
{
    private ?string $sessionId = null;
    private string $protocolVersion = '2025-06-18';

    /** @param array<string, string> $headers 附加请求头（来自 .mcp.json 的 headers） */
    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly float $connectTimeout = 15.0,
    ) {
    }

    public function request(array $payload, float $timeout): array
    {
        $status = 0;
        $respHeaders = [];
        $body = '';
        $this->post($payload, $timeout, $status, $respHeaders, $body);
        if ($status === 202) {
            throw new \RuntimeException("MCP HTTP server 对请求返回 202（无响应体）: {$this->url}");
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("MCP HTTP 请求失败（HTTP {$status}）: {$this->snippet($body)}");
        }
        if (isset($respHeaders['mcp-session-id']) && is_string($respHeaders['mcp-session-id']) && $respHeaders['mcp-session-id'] !== '') {
            $this->sessionId = $respHeaders['mcp-session-id'];
        }
        $contentType = strtolower((string) ($respHeaders['content-type'] ?? ''));
        if (str_contains($contentType, 'text/event-stream')) {
            return $this->fromSse($body, (int) ($payload['id'] ?? -1));
        }
        $msg = json_decode($body, true);
        if (!is_array($msg)) {
            throw new \RuntimeException("MCP HTTP 响应不是合法 JSON: {$this->snippet($body)}");
        }
        return $msg;
    }

    public function notify(array $payload): void
    {
        $status = 0;
        $respHeaders = [];
        $body = '';
        $this->post($payload, 10.0, $status, $respHeaders, $body);
        // 通知不重试不校验：2xx/202 均视为受理，其余静默容忍
    }

    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    public function close(): void
    {
        // HTTP 无长连接需要显式关闭
    }

    /**
     * 阻塞 POST 一次（工具调用在 agent 循环里是同步的，无需 Fiber 流式）。
     * 传引用带回：状态码、响应头（小写键）、响应体。
     */
    private function post(array $payload, float $timeout, int &$status, array &$respHeaders, string &$body): void
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'MCP-Protocol-Version: ' . $this->protocolVersion,
        ];
        if ($this->sessionId !== null) {
            $headers[] = "Mcp-Session-Id: {$this->sessionId}";
        }
        foreach ($this->headers as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }

        $ch = curl_init($this->url);
        if ($ch === false) {
            throw new \RuntimeException("curl 初始化失败: {$this->url}");
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int) ceil($this->connectTimeout),
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException("MCP HTTP 传输错误: {$err}");
        }
        $body = (string) $raw;
    }

    /** 解析 SSE：按空行分事件，拼接 data: 行，返回 id 匹配的消息 */
    private function fromSse(string $body, int $requestId): array
    {
        foreach (preg_split('/\n\s*\n/', trim($body)) ?: [] as $event) {
            $data = '';
            foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data .= trim(substr($line, 5));
                }
            }
            if ($data === '') {
                continue;
            }
            $msg = json_decode($data, true);
            if (is_array($msg) && ($msg['id'] ?? null) === $requestId) {
                return $msg;
            }
        }
        throw new \RuntimeException('MCP HTTP SSE 流中未找到匹配请求 id 的响应');
    }

    private function snippet(string $body): string
    {
        $trimmed = trim($body);
        return $trimmed === '' ? '(空响应体)' : mb_substr($trimmed, 0, 300);
    }
}
```

- [ ] **Step 5: 运行确认通过**

Run: `vendor/bin/phpunit tests/HttpTransportTest.php`
Expected: PASS（5 个测试）

- [ ] **Step 6: 提交**

```bash
git add src/Mcp/HttpTransport.php tests/fixtures/mcp/fake-http-server.php tests/HttpTransportTest.php
git commit -m "feat: Streamable HTTP 传输——curl 同步 POST + SSE 响应解析 + 会话头回传"
```

---

### Task 5: McpConnection + McpToolInfo（协议层）

**Files:**
- Create: `src/Mcp/McpToolInfo.php`
- Create: `src/Mcp/McpConnection.php`
- Test: `tests/McpConnectionTest.php`

**Interfaces:**
- Consumes: `Transport`
- Produces:
  - `McpToolInfo`：`__construct(public readonly string $name, public readonly string $description, public readonly array $inputSchema, public readonly bool $readOnly)`
  - `McpConnection`：
    - `const PROTOCOL_VERSION = '2025-06-18'`
    - `__construct(private readonly string $serverName, private readonly Transport $transport, private readonly float $timeout)`
    - `initializeAndListTools(): array` —— @return McpToolInfo[]，失败抛 `\RuntimeException`
    - `callTool(string $toolName, array $arguments): string` —— 传输失败标记 dead 后续快速失败；`isError` 抛异常带工具文本
    - `tools(): array` / `serverName(): string` / `status(): string`（'connected'|'dead'）/ `lastError(): string` / `close(): void`
    - `static contentToText(array $result): string`

- [ ] **Step 1: 写失败测试**

创建 `tests/McpConnectionTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Mcp\McpConnection;
use PhpAgent\Mcp\Transport;
use PHPUnit\Framework\TestCase;

/**
 * McpConnection：握手顺序、分页、isError、content 提取、断连快速失败。
 * 用 FakeTransport（匿名类）注入 canned 响应。
 */
final class McpConnectionTest extends TestCase
{
    private function makeFake(array $responses): object
    {
        return new class($responses) implements Transport {
            public array $sent = [];
            public array $responses;
            public int $requestCount = 0;
            public string $proto = '';

            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function request(array $payload, float $timeout): array
            {
                $this->sent[] = $payload;
                $this->requestCount++;
                $resp = array_shift($this->responses);
                if ($resp === null) {
                    throw new \RuntimeException('夹具响应已耗尽');
                }
                return $resp;
            }

            public function notify(array $payload): void
            {
                $this->sent[] = $payload;
            }

            public function setProtocolVersion(string $version): void
            {
                $this->proto = $version;
            }

            public function close(): void
            {
            }
        };
    }

    private function initResponse(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1,
            'result' => ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'serverInfo' => ['name' => 'fake', 'version' => '0']]];
    }

    public function testHandshakeOrderAndProtocolFallback(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a', 'description' => '', 'inputSchema' => []]]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $tools = $conn->initializeAndListTools();

        self::assertSame('initialize', $fake->sent[0]['method']);
        self::assertSame('2025-06-18', $fake->sent[0]['params']['protocolVersion']);
        self::assertSame('notifications/initialized', $fake->sent[1]['method']);
        self::assertSame('tools/list', $fake->sent[2]['method']);
        // server 应答 2025-03-26 → 回填传输层协议版本
        self::assertSame('2025-03-26', $fake->proto);
        self::assertCount(1, $tools);
        self::assertSame('connected', $conn->status());
    }

    public function testPaginationFollowsNextCursor(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a', 'description' => '', 'inputSchema' => []]], 'nextCursor' => 'page2']],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => [['name' => 'b', 'description' => '', 'inputSchema' => []]]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $tools = $conn->initializeAndListTools();

        self::assertSame(['a', 'b'], array_map(static fn ($t) => $t->name, $tools));
        self::assertSame('page2', $fake->sent[3]['params']['cursor']);
    }

    public function testReadOnlyHintParsed(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                ['name' => 'ro', 'description' => 'd', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => true]],
                ['name' => 'rw', 'description' => 'd', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => false]],
                ['name' => 'unknown', 'description' => 'd', 'inputSchema' => []],
            ]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $tools = $conn->initializeAndListTools();

        self::assertTrue($tools[0]->readOnly);
        self::assertFalse($tools[1]->readOnly);
        self::assertFalse($tools[2]->readOnly, '未声明注解 = 按需确认');
    }

    public function testCallToolExtractsTextContent(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [
                ['type' => 'text', 'text' => '第一行'],
                ['type' => 'image', 'data' => '...'],
                ['type' => 'text', 'text' => '第二行'],
            ]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        $out = $conn->callTool('x', []);
        self::assertSame("第一行\n[image 非文本内容]\n第二行", $out);
    }

    public function testStructuredContentFallback(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['structuredContent' => ['temp' => 25]]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        self::assertSame('{"temp":25}', $conn->callTool('x', []));
    }

    public function testIsErrorThrowsWithToolText(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => '夹具故意失败']], 'isError' => true]],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('夹具故意失败');
        $conn->callTool('fail', []);
    }

    public function testTransportFailureMarksDeadAndFastFails(): void
    {
        $fake = $this->makeFake([
            $this->initResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            // callTool 时抛传输异常
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $conn->initializeAndListTools();

        try {
            $conn->callTool('x', []);
            self::fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            self::assertSame('dead', $conn->status());
        }
        // 第二次调用快速失败，不再触达 transport
        $count = $fake->requestCount;
        try {
            $conn->callTool('x', []);
            self::fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('已断开', $e->getMessage());
        }
        self::assertSame($count, $fake->requestCount);
    }

    public function testServerErrorMessageSurfaced(): void
    {
        $fake = $this->makeFake([
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32601, 'message' => 'method not found']],
        ]);
        $conn = new McpConnection('fake', $fake, 5);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('method not found');
        $conn->initializeAndListTools();
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/McpConnectionTest.php`
Expected: FAIL —— `Class "PhpAgent\Mcp\McpConnection" not found`

- [ ] **Step 3: 实现**

创建 `src/Mcp/McpToolInfo.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * tools/list 返回的单个工具描述。
 */
final class McpToolInfo
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly bool $readOnly,
    ) {
    }
}
```

创建 `src/Mcp/McpConnection.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

/**
 * 单条 MCP 连接的协议层：initialize 握手 → tools/list（分页）→ tools/call。
 * 自增 id 关联响应；传输失败标记 dead 后快速失败（v1 不做运行时重连）。
 */
final class McpConnection
{
    public const PROTOCOL_VERSION = '2025-06-18';
    public const CLIENT_INFO = ['name' => 'php-agent', 'version' => '1.0.0'];

    private int $nextId = 1;
    /** @var 'connecting'|'connected'|'dead' */
    private string $status = 'connecting';
    private string $lastError = '';

    /** @var McpToolInfo[] */
    private array $tools = [];

    public function __construct(
        private readonly string $serverName,
        private readonly Transport $transport,
        private readonly float $timeout,
    ) {
    }

    /** @return McpToolInfo[] 失败抛 \RuntimeException（由 McpPlugin 转为该 server 的失败记录） */
    public function initializeAndListTools(): array
    {
        $resp = $this->rpc('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [],
            'clientInfo' => self::CLIENT_INFO,
        ]);
        $serverVersion = (string) ($resp['result']['protocolVersion'] ?? self::PROTOCOL_VERSION);
        $this->transport->setProtocolVersion($serverVersion);
        $this->transport->notify(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $tools = [];
        $cursor = null;
        do {
            $params = $cursor !== null ? ['cursor' => $cursor] : [];
            $resp = $this->rpc('tools/list', $params);
            $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
            foreach ($result['tools'] ?? [] as $t) {
                if (!is_array($t) || !isset($t['name']) || !is_string($t['name'])) {
                    continue;
                }
                $tools[] = new McpToolInfo(
                    $t['name'],
                    (string) ($t['description'] ?? ''),
                    is_array($t['inputSchema'] ?? null) ? $t['inputSchema'] : [],
                    is_array($t['annotations'] ?? null) && ($t['annotations']['readOnlyHint'] ?? false) === true,
                );
            }
            $cursor = isset($result['nextCursor']) && is_string($result['nextCursor']) ? $result['nextCursor'] : null;
        } while ($cursor !== null);

        $this->tools = $tools;
        $this->status = 'connected';
        return $tools;
    }

    /**
     * 调用工具并提取文本。isError / 传输失败抛 \RuntimeException
     * （ToolRegistry 统一转 'ERROR: ...' 回传模型）。
     */
    public function callTool(string $toolName, array $arguments): string
    {
        if ($this->status === 'dead') {
            throw new \RuntimeException("MCP server '{$this->serverName}' 已断开（{$this->lastError}），本进程内不再重试");
        }
        try {
            $resp = $this->rpc('tools/call', ['name' => $toolName, 'arguments' => $arguments]);
        } catch (\RuntimeException $e) {
            $this->status = 'dead';
            $this->lastError = $e->getMessage();
            throw $e;
        }
        $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
        $text = self::contentToText($result);
        if (!empty($result['isError'])) {
            throw new \RuntimeException($text !== '' ? $text : "MCP 工具 '{$toolName}' 返回 isError");
        }
        return $text;
    }

    /** content[] → 文本：text 拼接、structuredContent 兜底、非文本块占位 */
    public static function contentToText(array $result): string
    {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        if ($content === [] && isset($result['structuredContent'])) {
            return json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE);
        }
        $parts = [];
        foreach ($content as $block) {
            $type = is_array($block) ? (string) ($block['type'] ?? '') : '';
            if ($type === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            } elseif ($type !== '') {
                $parts[] = "[{$type} 非文本内容]";
            }
        }
        return implode("\n", $parts);
    }

    /** @return McpToolInfo[] */
    public function tools(): array
    {
        return $this->tools;
    }

    public function serverName(): string
    {
        return $this->serverName;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    public function close(): void
    {
        $this->transport->close();
    }

    private function rpc(string $method, array $params): array
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method];
        if ($params !== []) {
            $payload['params'] = $params;
        }
        $resp = $this->transport->request($payload, $this->timeout);
        if (isset($resp['error'])) {
            $msg = is_array($resp['error'])
                ? (string) ($resp['error']['message'] ?? json_encode($resp['error'], JSON_UNESCAPED_UNICODE))
                : (string) $resp['error'];
            throw new \RuntimeException("MCP server '{$this->serverName}' 返回错误（{$method}）: {$msg}");
        }
        return $resp;
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/McpConnectionTest.php`
Expected: PASS（8 个测试）

- [ ] **Step 5: 提交**

```bash
git add src/Mcp/McpToolInfo.php src/Mcp/McpConnection.php tests/McpConnectionTest.php
git commit -m "feat: McpConnection——握手/分页/工具调用/content 提取/断连快速失败"
```

---

### Task 6: McpToolAdapter（接入 Tool 契约）

**Files:**
- Create: `src/Mcp/McpToolAdapter.php`
- Test: `tests/McpToolAdapterTest.php`

**Interfaces:**
- Consumes: `PhpAgent\Agent\Tool`、`PhpAgent\Agent\ReadOnlyTool`（Task 1）、`McpConnection` + `McpToolInfo`（Task 5）
- Produces:
  - `McpToolAdapter::__construct(McpConnection $connection, McpToolInfo $info)`
  - `static sanitize(string $name): string` —— 只保留 `[A-Za-z0-9_-]`，其余替换 `_`
  - 完整 `Tool` 实现：`name()` = `mcp__<sanitized server>__<sanitized tool>`

- [ ] **Step 1: 写失败测试**

创建 `tests/McpToolAdapterTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\ReadOnlyTool;
use PhpAgent\Mcp\McpConnection;
use PhpAgent\Mcp\McpToolAdapter;
use PhpAgent\Mcp\McpToolInfo;
use PhpAgent\Mcp\Transport;
use PHPUnit\Framework\TestCase;

/**
 * McpToolAdapter：命名 sanitize、schema 兜底、readOnly 透传、execute 走 callTool。
 */
final class McpToolAdapterTest extends TestCase
{
    /** 造一条已完成握手、且下次 tools/call 返回固定文本的连接 */
    private function makeConnection(string $serverName, string $callResult): McpConnection
    {
        $fake = new class($callResult) implements Transport {
            public function __construct(private readonly string $callResult)
            {
            }

            public function request(array $payload, float $timeout): array
            {
                $id = $payload['id'];
                if ($payload['method'] === 'initialize') {
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'serverInfo' => ['name' => 'x', 'version' => '0']]];
                }
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => $this->callResult]]]];
            }

            public function notify(array $payload): void
            {
            }

            public function setProtocolVersion(string $version): void
            {
            }

            public function close(): void
            {
            }
        };
        $conn = new McpConnection($serverName, $fake, 5);
        $conn->initializeAndListTools();
        return $conn;
    }

    public function testSanitize(): void
    {
        self::assertSame('my_server-tool1', McpToolAdapter::sanitize('my server-tool.1'));
        self::assertSame('ok-name_2', McpToolAdapter::sanitize('ok-name_2'));
    }

    public function testAdapterContract(): void
    {
        $conn = $this->makeConnection('my server', $callResult = '工具输出');
        $info = new McpToolInfo('tool.1', '', [], true);
        $adapter = new McpToolAdapter($conn, $info);

        self::assertInstanceOf(ReadOnlyTool::class, $adapter);
        self::assertSame('mcp__my_server__tool_1', $adapter->name());
        self::assertSame('(MCP 工具，无描述)', $adapter->description(), '空描述有兜底文案');
        self::assertSame(['type' => 'object', 'properties' => []], $adapter->schema(), '空 schema 有兜底');
        self::assertTrue($adapter->readOnly());
        self::assertSame($callResult, $adapter->execute(['any' => 'input']));
    }

    public function testAdapterPassthroughSchemaAndDescription(): void
    {
        $conn = $this->makeConnection('srv', 'x');
        $schema = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]];
        $adapter = new McpToolAdapter($conn, new McpToolInfo('grep', '内容搜索', $schema, false));

        self::assertSame('mcp__srv__grep', $adapter->name());
        self::assertSame('内容搜索', $adapter->description());
        self::assertSame($schema, $adapter->schema());
        self::assertFalse($adapter->readOnly());
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/McpToolAdapterTest.php`
Expected: FAIL —— `Class "PhpAgent\Mcp\McpToolAdapter" not found`

- [ ] **Step 3: 实现**

创建 `src/Mcp/McpToolAdapter.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

use PhpAgent\Agent\ReadOnlyTool;
use PhpAgent\Agent\Tool;

/**
 * 把一条 MCP 工具包装成 agent 的 Tool：LLM 以 mcp__<server>__<tool> 调用，
 * execute 透传给所属连接的 tools/call。
 */
final class McpToolAdapter implements Tool, ReadOnlyTool
{
    public function __construct(
        private readonly McpConnection $connection,
        private readonly McpToolInfo $info,
    ) {
    }

    public function name(): string
    {
        return 'mcp__' . self::sanitize($this->connection->serverName()) . '__' . self::sanitize($this->info->name);
    }

    public function description(): string
    {
        return $this->info->description !== '' ? $this->info->description : '(MCP 工具，无描述)';
    }

    public function schema(): array
    {
        return $this->info->inputSchema !== [] ? $this->info->inputSchema : ['type' => 'object', 'properties' => []];
    }

    public function readOnly(): bool
    {
        return $this->info->readOnly;
    }

    public function execute(array $input): string
    {
        return $this->connection->callTool($this->info->name, $input);
    }

    /** 只保留 [A-Za-z0-9_-]，其余替换 _，保证 LLM 侧工具名合法 */
    public static function sanitize(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
    }
}
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/McpToolAdapterTest.php`
Expected: PASS（3 个测试）

- [ ] **Step 5: 提交**

```bash
git add src/Mcp/McpToolAdapter.php tests/McpToolAdapterTest.php
git commit -m "feat: McpToolAdapter——MCP 工具接入 Tool/ReadOnlyTool 契约"
```

---

### Task 7: McpPlugin + McpStatusCommand + 全部接线

**Files:**
- Create: `src/Plugins/McpPlugin.php`
- Create: `src/Mcp/McpStatusCommand.php`
- Modify: `src/Config.php`（加 `mcpEnabled()`）
- Modify: `src/Kernel/PluginManager.php`（builtin 清单追加 McpPlugin）
- Modify: `bin/agent`（ConfirmHook 补传 registry）
- Test: `tests/McpPluginTest.php`

**Interfaces:**
- Consumes: 前六个任务的全部产物；`PhpAgent\Kernel\Plugin`、`AgentKernel`、`PhpAgent\Command\Command`、`CommandContext`
- Produces:
  - `Config::mcpEnabled(): bool`（`AGENT_MCP`，默认 on，`off` 关闭）
  - 插件 id `'mcp'`；注册命令 `/mcp`
  - `McpStatusCommand::__construct(array $specs, array $connections, array $failures)`

- [ ] **Step 1: 写失败测试**

创建 `tests/McpPluginTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Command\CommandContext;
use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * McpPlugin 装配：临时目录当项目根（自带 .env），stdio 夹具做真连接，
 * 验证工具注册、真实调用、/mcp 命令、AGENT_MCP=off、损坏配置降级。
 */
final class McpPluginTest extends TestCase
{
    private string $root;
    private string $workspace;

    protected function setUp(): void
    {
        // 临时目录充当项目根：Config 只读这里的 .env，PluginManager 只扫这里的插件，
        // 与开发者本机的真实 .env / plugins/ 完全隔离
        $this->root = sys_get_temp_dir() . '/php-agent-mcp-root-' . uniqid();
        $this->workspace = sys_get_temp_dir() . '/php-agent-mcp-ws-' . uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/{.env,.mcp.json}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->workspace . '/.mcp.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        @rmdir($this->workspace);
    }

    private function writeMcpJson(): void
    {
        $fixture = dirname(__DIR__) . '/tests/fixtures/mcp/fake-stdio-server.php';
        file_put_contents($this->workspace . '/.mcp.json', json_encode([
            'mcpServers' => ['fake' => ['command' => PHP_BINARY, 'args' => [$fixture], 'timeout' => 5]],
        ]));
    }

    private function boot(): AgentKernel
    {
        file_put_contents($this->root . '/.env', "AGENT_WORKSPACE={$this->workspace}\n");
        $kernel = new AgentKernel(Config::load($this->root));
        PluginManager::load($kernel, $this->root);
        return $kernel;
    }

    public function testToolsRegisteredAndExecutable(): void
    {
        $this->writeMcpJson();
        $kernel = $this->boot();
        $names = $kernel->toolRegistry()->names();

        self::assertContains('mcp__fake__echo', $names);
        self::assertContains('mcp__fake__ping', $names);
        self::assertContains('mcp__fake__touch', $names);
        self::assertSame('echo: 你好', $kernel->toolRegistry()->execute('mcp__fake__echo', ['text' => '你好']));
    }

    public function testMcpCommandShowsStatus(): void
    {
        $this->writeMcpJson();
        $kernel = $this->boot();
        $cmd = $kernel->command('mcp');
        self::assertNotNull($cmd);
        $out = $cmd->execute([], new CommandContext($kernel, [], 't'));
        self::assertStringContainsString('fake', $out);
        self::assertStringContainsString('stdio', $out);
        self::assertStringContainsString('echo', $out);
    }

    public function testDisabledByAgentMcpOff(): void
    {
        $this->writeMcpJson();
        file_put_contents($this->root . '/.env', "AGENT_WORKSPACE={$this->workspace}\nAGENT_MCP=off\n");
        $kernel = new AgentKernel(Config::load($this->root));
        PluginManager::load($kernel, $this->root);

        self::assertNotContains('mcp__fake__echo', $kernel->toolRegistry()->names());
        self::assertNull($kernel->command('mcp'));
    }

    public function testMissingConfigIsSilent(): void
    {
        $kernel = $this->boot(); // workspace 无 .mcp.json
        self::assertNotContains('mcp__fake__echo', $kernel->toolRegistry()->names());
    }

    public function testCorruptConfigSkipsAll(): void
    {
        file_put_contents($this->workspace . '/.mcp.json', '{ broken');
        $kernel = $this->boot(); // 只警告不崩溃
        self::assertNotContains('mcp__fake__echo', $kernel->toolRegistry()->names());
        self::assertSame([], array_values(array_filter(
            $kernel->toolRegistry()->names(),
            static fn (string $n) => str_starts_with($n, 'mcp__')
        )));
    }

    public function testFailingServerSkippedGracefully(): void
    {
        // 启动不了的命令 → 该 server 警告后跳过，其余照常（agent 正常可用）
        file_put_contents($this->workspace . '/.mcp.json', json_encode(['mcpServers' => [
            'dead' => ['command' => 'definitely-not-a-real-cmd-xyz'],
        ]]));
        $kernel = $this->boot();

        self::assertSame([], array_values(array_filter(
            $kernel->toolRegistry()->names(),
            static fn (string $n) => str_starts_with($n, 'mcp__')
        )));
        $out = $kernel->command('mcp')->execute([], new CommandContext($kernel, [], 't'));
        self::assertStringContainsString('连接失败', $out);
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/McpPluginTest.php`
Expected: FAIL —— 工具列表里没有 `mcp__fake__echo`（插件还不存在）

- [ ] **Step 3: 实现 McpStatusCommand**

创建 `src/Mcp/McpStatusCommand.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Mcp;

use PhpAgent\Command\Command;
use PhpAgent\Command\CommandContext;

/**
 * /mcp：展示每个 server 的状态、传输类型与工具清单。
 *
 * @param array<string, McpServerSpec> $specs
 * @param array<string, McpConnection> $connections
 * @param array<string, string> $failures server 名 => 失败原因
 */
final class McpStatusCommand implements Command
{
    public function __construct(
        private readonly array $specs,
        private readonly array $connections,
        private readonly array $failures,
    ) {
    }

    public function description(): string
    {
        return '查看 MCP server 状态与工具';
    }

    public function execute(array $args, CommandContext $ctx): string
    {
        if ($this->specs === []) {
            return '(未配置 MCP server：在工作区根目录放 .mcp.json，格式见 README)';
        }
        $lines = ['MCP server 状态:'];
        foreach ($this->specs as $name => $spec) {
            $conn = $this->connections[$name] ?? null;
            if ($conn === null) {
                $lines[] = sprintf('  ✗ %-14s [%s] 连接失败: %s', $name, $spec->type, $this->failures[$name] ?? '未知原因');
                continue;
            }
            $toolNames = implode(', ', array_map(
                static fn (McpToolInfo $t) => McpToolAdapter::sanitize($t->name),
                $conn->tools()
            ));
            $lines[] = sprintf(
                '  ✓ %-14s [%s] %d 个工具%s',
                $name,
                $spec->type,
                count($conn->tools()),
                $toolNames !== '' ? ': ' . $toolNames : ''
            );
        }
        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: 实现 McpPlugin**

创建 `src/Plugins/McpPlugin.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Plugins;

use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\Plugin;
use PhpAgent\Mcp\HttpTransport;
use PhpAgent\Mcp\McpConfig;
use PhpAgent\Mcp\McpConnection;
use PhpAgent\Mcp\McpServerSpec;
use PhpAgent\Mcp\McpStatusCommand;
use PhpAgent\Mcp\McpToolAdapter;
use PhpAgent\Mcp\StdioTransport;
use PhpAgent\Mcp\Transport;

/**
 * MCP 客户端插件：读 <workspace>/.mcp.json → 逐个连接 → 工具挂进 ToolRegistry。
 * 配置缺失静默跳过；单个 server 失败警告后跳过，不阻塞其余工具。
 * 退出时由 register_shutdown_function 统一关闭连接（杀 stdio 子进程）。
 */
final class McpPlugin implements Plugin
{
    /** @var array<string, McpConnection> */
    private array $connections = [];

    /** @var array<string, string> server 名 => 失败原因 */
    private array $failures = [];

    public function name(): string
    {
        return 'mcp';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(AgentKernel $kernel): void
    {
        if (!$kernel->config()->mcpEnabled()) {
            return;
        }
        try {
            $loaded = McpConfig::load($kernel->config()->workspace());
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "⚠ MCP 配置解析失败: {$e->getMessage()}（已跳过全部 MCP server）\n");
            return;
        }
        foreach ($loaded['errors'] as $name => $err) {
            fwrite(STDERR, "⚠ MCP server '{$name}' 配置无效: {$err}（已跳过）\n");
            $this->failures[$name] = "配置无效: {$err}";
        }

        foreach ($loaded['servers'] as $spec) {
            $conn = $this->connect($spec);
            if ($conn !== null) {
                $this->connections[$spec->name] = $conn;
            }
        }

        foreach ($this->connections as $conn) {
            foreach ($conn->tools() as $info) {
                $kernel->registerTool(new McpToolAdapter($conn, $info));
            }
        }

        $kernel->registerCommand('mcp', new McpStatusCommand(
            $loaded['servers'],
            $this->connections,
            $this->failures
        ));

        $conns = $this->connections;
        register_shutdown_function(static function () use ($conns): void {
            foreach ($conns as $conn) {
                $conn->close();
            }
        });
    }

    private function connect(McpServerSpec $spec): ?McpConnection
    {
        $transport = null;
        try {
            $transport = match ($spec->type) {
                'stdio' => new StdioTransport(
                    $spec->config['command'],
                    $spec->config['args'],
                    $spec->config['env']
                ),
                'http' => new HttpTransport(
                    $spec->config['url'],
                    $spec->config['headers']
                ),
            };
            $conn = new McpConnection($spec->name, $transport, $spec->timeout);
            $conn->initializeAndListTools();
            return $conn;
        } catch (\Throwable $e) {
            $this->failures[$spec->name] = $e->getMessage();
            if ($transport instanceof Transport) {
                $transport->close();
            }
            fwrite(STDERR, "⚠ MCP server '{$spec->name}' 连接失败: {$e->getMessage()}（已跳过）\n");
            return null;
        }
    }
}
```

- [ ] **Step 5: 接线 Config / PluginManager / bin/agent**

`src/Config.php` 在 `pluginsSwitch()` 方法后加：

```php
    /** MCP 客户端开关：on（默认）| off（不加载 McpPlugin、不连任何 server） */
    public function mcpEnabled(): bool
    {
        return strtolower(trim($this->env['AGENT_MCP'] ?? 'on')) !== 'off';
    }
```

`src/Kernel/PluginManager.php`：use 区加 `use PhpAgent\Plugins\McpPlugin;`；`builtin()` 返回数组末尾（CommandsPlugin 之后）追加：

```php
            CommandsPlugin::class,
            McpPlugin::class,
```

`bin/agent`：ConfirmHook 构造改为（补传第二参数）：

```php
    $kernel->addHook(new ConfirmHook(
        static fn (string $tool, array $input): string => $ui->promptTool($tool, $input),
        $kernel->toolRegistry()
    ));
```

- [ ] **Step 6: 运行确认通过**

Run: `vendor/bin/phpunit tests/McpPluginTest.php`
Expected: PASS（6 个测试）

全量回归：`vendor/bin/phpunit`
Expected: 全部 PASS（PluginManagerTest 的 `assertGreaterThanOrEqual(6, count($plugins))` 与命令子集断言不受影响）

- [ ] **Step 7: 提交**

```bash
git add src/Plugins/McpPlugin.php src/Mcp/McpStatusCommand.php src/Config.php src/Kernel/PluginManager.php bin/agent tests/McpPluginTest.php
git commit -m "feat: McpPlugin 装配——启动连接、/mcp 命令、AGENT_MCP 开关与 ConfirmHook 接线"
```

---

### Task 8: 权限端到端测试 + 文档（README / .env.example / CHANGELOG）

**Files:**
- Create: `tests/McpPermissionTest.php`
- Modify: `README.md`（CLI 命令表 + 新增「MCP 工具（客户端）」章节）
- Modify: `.env.example`（AGENT_MCP 配置说明）
- Modify: `CHANGELOG.md`（[Unreleased] 新增条目）

**Interfaces:**
- Consumes: Task 1 的 ConfirmHook/ReadOnlyTool、Task 7 的完整装配
- Produces: 无新代码接口；文档与端到端验证

- [ ] **Step 1: 写失败测试（readOnlyHint 端到端放行）**

创建 `tests/McpPermissionTest.php`：

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PhpAgent\Hook\ConfirmHook;
use PhpAgent\Hook\HookVetoException;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * MCP × 权限：readOnlyHint 工具自动放行，未声明/声明确认的工具走 ConfirmHook。
 * 真子进程 stdio 夹具全链路验证。
 */
final class McpPermissionTest extends TestCase
{
    private string $root;
    private string $workspace;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php-agent-mcp-perm-root-' . uniqid();
        $this->workspace = sys_get_temp_dir() . '/php-agent-mcp-perm-ws-' . uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/.env');
        @unlink($this->workspace . '/.mcp.json');
        @rmdir($this->root);
        @rmdir($this->workspace);
    }

    private function boot(): AgentKernel
    {
        $fixture = dirname(__DIR__) . '/tests/fixtures/mcp/fake-stdio-server.php';
        file_put_contents($this->workspace . '/.mcp.json', json_encode([
            'mcpServers' => ['fake' => ['command' => PHP_BINARY, 'args' => [$fixture], 'timeout' => 5]],
        ]));
        file_put_contents($this->root . '/.env', "AGENT_WORKSPACE={$this->workspace}\n");
        $kernel = new AgentKernel(Config::load($this->root));
        PluginManager::load($kernel, $this->root);
        return $kernel;
    }

    public function testReadOnlyHintToolBypassesConfirmation(): void
    {
        $kernel = $this->boot();
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'y';
        }, $kernel->toolRegistry());

        $hook->handle('before_tool_call', ['tool' => 'mcp__fake__ping', 'input' => []]); // readOnlyHint=true
        self::assertSame([], $calls, 'readOnlyHint 工具不应触发确认');
    }

    public function testDeclaredWriteToolRequiresConfirmation(): void
    {
        $kernel = $this->boot();
        $calls = [];
        $hook = new ConfirmHook(function (string $tool, array $input) use (&$calls): string {
            $calls[] = $tool;
            return 'n';
        }, $kernel->toolRegistry());

        $this->expectException(HookVetoException::class);
        try {
            $hook->handle('before_tool_call', ['tool' => 'mcp__fake__touch', 'input' => []]); // readOnlyHint=false
        } finally {
            self::assertSame(['mcp__fake__touch'], $calls);
        }
    }
}
```

- [ ] **Step 2: 运行确认通过**

Run: `vendor/bin/phpunit tests/McpPermissionTest.php`
Expected: PASS（装配在 Task 7 已完成，此任务只补端到端验证；若 FAIL 说明 Task 1/7 集成有回归，先修复再继续）

- [ ] **Step 3: 更新 README**

`README.md` 的「CLI 命令」表格加一行（`/exit` 行之前）：

```markdown
| `/mcp` | 查看 MCP server 状态与工具清单 |
```

「插件系统」章节之后新增：

```markdown
## MCP 工具（客户端）

在工作区根目录放 `.mcp.json`（格式与 Claude Code / Cursor 兼容），启动即自动连接并把
server 的工具挂进工具列表（`/tools` 可见，名称形如 `mcp__<server>__<tool>`）：

```json
{
  "mcpServers": {
    "filesystem": { "command": "npx", "args": ["-y", "@modelcontextprotocol/server-filesystem", "/tmp"] },
    "remote": { "url": "https://mcp.example.com/mcp", "headers": { "Authorization": "Bearer xxx" } }
  }
}
```

- 传输：`command` = 本地 stdio 子进程；`url` = Streamable HTTP（远程）
- 每条目可选 `timeout`（秒，默认 30）、`env`（stdio 环境变量）、`headers`（HTTP 请求头）
- 权限：server 声明 `readOnlyHint: true` 的工具自动放行，其余在 ask 模式下先确认
- `/mcp` 查看 server 状态与工具清单；`AGENT_MCP=off` 整体禁用
- 退出时自动清理 stdio 子进程；单个 server 连接失败不影响其余工具
- 首版仅消费 tools 能力（resources / prompts / sampling 暂不支持），协议版本 2025-06-18
```

- [ ] **Step 4: 更新 .env.example**

在「── 权限与审计 ──」块之前插入：

```
# ── MCP 工具（客户端）──────────────────────────────────────
# 在工作区根目录放 .mcp.json 即可接入外部 MCP server（格式与 Claude Code/Cursor 兼容）：
# {
#   "mcpServers": {
#     "filesystem": { "command": "npx", "args": ["-y", "@modelcontextprotocol/server-filesystem", "/tmp"] },
#     "remote": { "url": "https://mcp.example.com/mcp", "headers": { "Authorization": "Bearer xxx" } }
#   }
# }
# /mcp 查看 server 状态；readOnlyHint 工具自动放行，其余按权限模式确认
# AGENT_MCP=off
```

- [ ] **Step 5: 更新 CHANGELOG**

`CHANGELOG.md` 的 `## [Unreleased]` 下 `### 新增` 列表**最前面**插入：

```markdown
- **MCP 客户端（tools + stdio + Streamable HTTP）**
  - 工作区 `.mcp.json`（Claude Code/Cursor 兼容格式）声明 server，启动自动连接，
    工具以 `mcp__<server>__<tool>` 挂进 ToolRegistry 供 LLM 直接调用
  - 传输：本地 stdio 子进程（proc_open + stream_select 超时）+ 远程 Streamable HTTP
    （curl 同步 POST，解析 JSON/SSE 双形态响应，Mcp-Session-Id 回传）；零新依赖
  - 权限：readOnlyHint 注解工具自动放行（新增 ReadOnlyTool 标记接口 + ConfirmHook
    动态查询 ToolRegistry），其余走 ask 确认；`/mcp` 查看状态；`AGENT_MCP=off` 禁用；
    退出时自动清理子进程；单 server 失败不阻塞其余工具
  - 设计文档：docs/superpowers/specs/2026-11-11-mcp-client-design.md
```

- [ ] **Step 6: 全量回归**

Run: `vendor/bin/phpunit`
Expected: 全部 PASS（0 failures）

Run: `php -l bin/agent && php -l src/Plugins/McpPlugin.php`（已在前面任务验证过语法，此处属最终保险）
Expected: 无语法错误输出

- [ ] **Step 7: 提交**

```bash
git add tests/McpPermissionTest.php README.md .env.example CHANGELOG.md
git commit -m "docs+test: MCP 权限端到端验证 + README/.env.example/CHANGELOG 文档"
```
