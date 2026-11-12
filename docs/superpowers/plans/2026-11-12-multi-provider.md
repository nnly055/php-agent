# 多平台 × 多模型 × 对话内切换 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 多平台（各带凭据）× 每平台多模型；对话输入区选择「平台 ▸ 模型」下一条消息即生效；选择按会话持久化；设置保存后 HTTP 热更新运行中的 sidecar，无需重启。

**Architecture:** PHP 端新增 `ProfileRegistry`（按 profileId+model 惰性构造缓存 client），`/agent` 请求携带 `profile_id`/`model` → `AgentLoop::setClient` per-turn 切换；`POST /config/profiles` 热更新凭据全集；选择随 `SessionMeta` 持久化（四个 store 实现）。Rust 端 desktop.json 升级为 `profiles[]`（旧 `config` 无损迁移），spawn 仍注入默认档 env 兜底。前端 SettingsDialog 重构为平台管理，Composer 加选择器。

**Tech Stack:** PHP 8.1+（phpunit）、Tauri 2 + Rust（cargo test）、React + zustand + vitest。不新增依赖。

**Spec:** `docs/superpowers/specs/2026-11-12-multi-provider-design.md`

## Global Constraints

- 不新增任何依赖（composer / cargo / npm 均不动）
- 多把 API key 同存 `~/.php-agent/desktop.json`（0600）；不出现在日志、审计、终端输出中
- 注释与提交信息用中文，`feat:`/`fix:`/`test:`/`docs:` 前缀
- CLI（`bin/agent`）行为零变化——现有测试守护
- provider 合法值固定 `openai | anthropic | fake`，三端一致
- Rust 序列化 camelCase（前端 Profile 字段对齐）；持久化文件权限 0600
- 保存设置**不得**重启 sidecar（热更新是本功能核心语义）
- 测试命令：`vendor/bin/phpunit`（仓库根）、`cargo test`（`desktop/src-tauri/`）、`npm test && npx tsc -b`（`desktop/`）

---

### Task 1: PHP —— SessionMeta 扩展 + 四个 store 的选择持久化

**Files:**
- Modify: `src/Session/SessionMeta.php`
- Modify: `src/Session/SessionStore.php`（接口）
- Modify: `src/Session/Store/InMemorySessionStore.php`、`JsonFileSessionStore.php`、`Sqlite3SessionStore.php`、`RedisSessionStore.php`
- Test: `tests/SessionStoreTest.php`（追加）

**Interfaces:**
- Produces（Task 3/4 依赖）：
  - `SessionMeta` 新增 `public readonly ?string $profileId`、`?string $model`（构造参数带默认 null；`toArray()` 输出 `profileId`/`model` 键；`fromArray` 缺省 null）
  - `SessionStore::save(string $sessionId, array $messages, ?array $selection = null): void`；`$selection = ['profileId' => ?string, 'model' => ?string]`，null = 保留旧值
  - 各实现 `list()` 返回的 meta 携带选择

- [ ] **Step 1: 写失败测试**（`tests/SessionStoreTest.php` 追加；该文件已有对四个实现的参数化或逐个用例——先读文件，按现有风格在**每个实现共用**的断言处追加；若为逐实现用例，给出一个共享私有断言方法）

```php
    /** 选择持久化：save 传 selection 落盘，null 保留旧值，缺省新会话为 null（继承默认档） */
    private function assertSelectionPersistence(SessionStore $store): void
    {
        $store->save('sel-1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]]);
        self::assertNull($store->list(10)[0]->profileId ?? null, '初始无选择');
        // 用 list 找到 sel-1（list 按 updatedAt 排序，多会话时不稳定）——按 id 过滤
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'sel-1'))[0];
        self::assertNull($meta->profileId);

        $store->save('sel-1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]], ['profileId' => 'p-1', 'model' => 'glm-4.7']);
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'sel-1'))[0];
        self::assertSame('p-1', $meta->profileId);
        self::assertSame('glm-4.7', $meta->model);

        $store->save('sel-1', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]]); // null = 保留
        $meta = array_values(array_filter($store->list(50), static fn ($m) => $m->id === 'sel-1'))[0];
        self::assertSame('p-1', $meta->profileId, 'null selection 保留旧值');
    }
```

在文件中每个 store 的既有测试类/数据提供者处，对四个实现各调用一次 `$this->assertSelectionPersistence(new XStore(...))`（构造参数照抄同文件中已有用例的实例化方式；Redis 用例若标记为跳过/需连接，则同样跟随现有跳过策略）。

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/SessionStoreTest.php`
Expected: FAIL（`list()[0]->profileId` 属性不存在 / null 断言失败）

- [ ] **Step 3: 实现**

① `SessionMeta`：构造函数追加 `?string $profileId = null, ?string $model = null`（readonly 属性同步声明）；`toArray()` 追加 `'profileId' => $this->profileId, 'model' => $this->model`；`fromArray` 追加 `$_['profileId'] ?? null`、`$_['model'] ?? null`（强转 string，null 容忍：`(isset($a['profileId']) ? (string) $a['profileId'] : null)`）。

② `SessionStore` 接口：`public function save(string $sessionId, array $messages, ?array $selection = null): void;`（docblock 注明 selection 语义）。

③ 四个实现——save() 构造 SessionMeta 处统一模式：`$sel = $selection ?? [];` 然后
`profileId: $sel['profileId'] ?? <旧值> ?? null`、`model: $sel['model'] ?? <旧值> ?? null`。
旧值来源：json/redis `$old['meta']['profileId'] ?? null`；memory `$old?->profileId`；
sqlite 改为把选择存进 **payload 的 meta 块**（不加列，免表结构迁移）：

```php
        // payload 组装处：
        $oldPayload = is_array($old) ? $old : []; // sqlite 里 $old 来自 SELECT payload
        $payload = ['schemaVersion' => 1,
            'meta' => ['profileId' => $sel['profileId'] ?? ($oldPayload['meta']['profileId'] ?? null),
                       'model' => $sel['model'] ?? ($oldPayload['meta']['model'] ?? null)],
            'messages' => $messages];
```

sqlite 的 `list()`：SELECT 增加 `payload` 列，构造 SessionMeta 时第 6/7 参从
`json_decode(payload)['meta']` 取（json_decode 一次，is_array 容错）。sqlite save() 里
`$old` 现为 `SELECT payload` 的行——按现有代码结构调整为先取 payload 再组装。
memory 的 `$old` 是 SessionMeta 对象 → `$old?->profileId`。

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/SessionStoreTest.php`，然后全量 `vendor/bin/phpunit`
Expected: PASS（SessionMeta 构造默认参数保证其余调用点零改动）

- [ ] **Step 5: 提交**

```bash
git add src/Session tests/SessionStoreTest.php
git commit -m "feat: 会话选择持久化——SessionMeta 增 profileId/model，save 支持 selection（四实现）"
```

---

### Task 2: PHP —— ProfileRegistry + kernel 访问器

**Files:**
- Create: `src/Server/ProfileRegistry.php`
- Modify: `src/Kernel/AgentKernel.php`（追加可空 registry 访问器）
- Test: `tests/ProfileRegistryTest.php`（新建）

**Interfaces:**
- Consumes: `Config::fromArray`、`OpenAICompatClient`/`AnthropicClient`（Config 构造）、`FakeLLMClient`（脚本数组构造——fake 委托 kernel 工厂）、`AgentKernel::provider/hasProvider`
- Produces（Task 3/4 依赖）：
  - `new ProfileRegistry(?AgentKernel $kernel = null)`
  - `setProfiles(array $profiles, string $defaultProfileId): void`（校验失败抛 `InvalidArgumentException`）
  - `profiles(): array{profiles: array, defaultProfile: string}`
  - `resolve(?string $profileId, ?string $model): LLMClient`
  - `activeClient(): ?LLMClient` / `setActiveClient(LLMClient $c): void`
  - `AgentKernel::setProfileRegistry(ProfileRegistry $r)` / `profileRegistry(): ?ProfileRegistry`

- [ ] **Step 1: 写失败测试**（`tests/ProfileRegistryTest.php` 新建；kernel 用例参考 `tests/PluginManagerTest.php` 的 kernel 构造方式——`new AgentKernel(Config::fromArray([...]))` 配 fake provider 注册或经 PluginManager::load）

```php
<?php
declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\LLM\LLMClient;
use PhpAgent\Server\ProfileRegistry;
use PHPUnit\Framework\TestCase;

final class ProfileRegistryTest extends TestCase
{
    private function kernel(): AgentKernel
    {
        $k = new AgentKernel(Config::fromArray(['AGENT_PROVIDER' => 'fake', 'AGENT_SESSION_STORE' => 'memory']));
        $k->registerProvider('fake', static fn () => new \PhpAgent\LLM\FakeLLMClient([]));
        return $k;
    }

    private function profiles(): array
    {
        return [
            ['id' => 'p-1', 'name' => 'DeepSeek', 'provider' => 'openai', 'baseUrl' => 'https://api.deepseek.com', 'apiKey' => 'sk-a', 'models' => ['deepseek-chat', 'deepseek-reasoner']],
            ['id' => 'p-2', 'name' => 'GLM', 'provider' => 'anthropic', 'baseUrl' => 'https://open.bigmodel.cn', 'apiKey' => 'sk-b', 'models' => ['glm-4.7']],
        ];
    }

    public function testSetProfilesValidates(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $this->expectException(\InvalidArgumentException::class);
        $r->setProfiles([], 'x'); // 空列表
    }

    public function testSetProfilesRejectsUnknownDefault(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $this->expectException(\InvalidArgumentException::class);
        $r->setProfiles($this->profiles(), 'nope');
    }

    public function testSetProfilesRejectsDuplicateIds(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $p = $this->profiles();
        $p[1]['id'] = 'p-1';
        $this->expectException(\InvalidArgumentException::class);
        $r->setProfiles($p, 'p-1');
    }

    public function testProfilesRoundTrip(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->profiles(), 'p-1');
        self::assertSame(['profiles' => $this->profiles(), 'defaultProfile' => 'p-1'], $r->profiles());
    }

    public function testResolveDefaultsToDefaultProfileFirstModel(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->profiles(), 'p-1');
        self::assertInstanceOf(LLMClient::class, $r->resolve(null, null));
        self::assertInstanceOf(LLMClient::class, $r->resolve('p-2', null));
    }

    public function testResolveUnknownProfileThrows(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->profiles(), 'p-1');
        $this->expectException(\InvalidArgumentException::class);
        $r->resolve('ghost', null);
    }

    public function testResolveCachesPerProfileModel(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->profiles(), 'p-1');
        self::assertSame($r->resolve('p-1', 'deepseek-chat'), $r->resolve('p-1', 'deepseek-chat'), '同键缓存同实例');
        self::assertNotSame($r->resolve('p-1', 'deepseek-chat'), $r->resolve('p-1', 'deepseek-reasoner'), '不同 model 不同实例');
    }

    public function testFakeDelegatesToKernel(): void
    {
        $k = $this->kernel();
        $r = new ProfileRegistry($k);
        $p = [['id' => 'f', 'name' => 'Fake', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['fake-model']]];
        $r->setProfiles($p, 'f');
        self::assertInstanceOf(LLMClient::class, $r->resolve('f', null));
    }

    public function testActiveClientAccessor(): void
    {
        $r = new ProfileRegistry($this->kernel());
        self::assertNull($r->activeClient());
        $r->setActiveClient($r->resolve(null, null));
        self::assertInstanceOf(LLMClient::class, $r->activeClient());
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/ProfileRegistryTest.php`
Expected: FAIL（类不存在）

- [ ] **Step 3: 实现 ProfileRegistry**（完整代码）

```php
<?php

declare(strict_types=1);

namespace PhpAgent\Server;

use PhpAgent\Agent\AgentKernel; // 若命名空间为 Kernel 以实际文件为准
use PhpAgent\Config;
use PhpAgent\LLM\AnthropicClient;
use PhpAgent\LLM\LLMClient;
use PhpAgent\LLM\OpenAICompatClient;

/**
 * 多平台凭据注册表：profiles（平台 × 模型目录）→ 惰性构造并缓存 LLM client。
 * per-turn 切换：/agent 携带 profile_id/model → resolve() → AgentLoop::setClient。
 * key 只在本对象与 desktop.json 中存在；本类不做任何日志/审计输出。
 */
final class ProfileRegistry
{
    /** @var array<string, array{id:string,name:string,provider:string,baseUrl:string,apiKey:string,models:array<int,string>}> */
    private array $profiles = [];
    private string $defaultProfileId = '';
    /** @var array<string, LLMClient> */
    private array $clients = [];
    private ?LLMClient $activeClient = null;

    public function __construct(private ?\PhpAgent\Kernel\AgentKernel $kernel = null)
    {
    }

    public function setProfiles(array $profiles, string $defaultProfileId): void
    {
        if ($profiles === []) {
            throw new \InvalidArgumentException('profiles 不能为空');
        }
        $ids = [];
        foreach ($profiles as $p) {
            $id = (string) ($p['id'] ?? '');
            if ($id === '' || isset($ids[$id])) {
                throw new \InvalidArgumentException("profile id 非法或重复: {$id}");
            }
            if (!in_array($p['provider'] ?? '', ['openai', 'anthropic', 'fake'], true)) {
                throw new \InvalidArgumentException("provider 必须是 openai|anthropic|fake: {$id}");
            }
            $ids[$id] = true;
        }
        if (!isset($ids[$defaultProfileId])) {
            throw new \InvalidArgumentException('defaultProfile 必须在 profiles 中');
        }
        $this->profiles = $profiles;
        $this->defaultProfileId = $defaultProfileId;
        $this->clients = []; // 凭据可能已变，清缓存
    }

    /** @return array{profiles: array, defaultProfile: string} */
    public function profiles(): array
    {
        return ['profiles' => $this->profiles, 'defaultProfile' => $this->defaultProfileId];
    }

    public function resolve(?string $profileId, ?string $model): LLMClient
    {
        $pid = $profileId !== null && $profileId !== '' ? $profileId : $this->defaultProfileId;
        $profile = $this->profiles[$pid]
            ?? throw new \InvalidArgumentException("未知 profile: {$pid}");
        $model = $model !== null && $model !== '' ? $model : (string) ($profile['models'][0] ?? '');
        $key = $pid . '|' . $model;
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }
        $client = match ($profile['provider']) {
            'fake' => $this->kernel?->provider('fake') ?? new \PhpAgent\LLM\FakeLLMClient([]),
            'anthropic' => new AnthropicClient($this->configFor($profile, $model)),
            default => new OpenAICompatClient($this->configFor($profile, $model)),
        };
        return $this->clients[$key] = $client;
    }

    private function configFor(array $profile, string $model): Config
    {
        return Config::fromArray([
            'AGENT_PROVIDER' => (string) $profile['provider'],
            'AGENT_API_KEY' => (string) ($profile['apiKey'] ?? ''),
            'AGENT_BASE_URL' => (string) ($profile['baseUrl'] ?? ''),
            'AGENT_MODEL' => $model,
        ]);
    }

    public function activeClient(): ?LLMClient
    {
        return $this->activeClient;
    }

    public function setActiveClient(LLMClient $client): void
    {
        $this->activeClient = $client;
    }
}
```

（注意：文件顶部 kernel 的命名空间以 `src/Kernel/AgentKernel.php` 实际为准——是
`PhpAgent\Kernel\AgentKernel`；上面 use 注释行按实际写。）

**AgentKernel 追加**（`sessionStore()` 访问器附近）：

```php
    private ?\PhpAgent\Server\ProfileRegistry $profileRegistry = null;

    public function setProfileRegistry(\PhpAgent\Server\ProfileRegistry $r): void
    {
        $this->profileRegistry = $r;
    }

    public function profileRegistry(): ?\PhpAgent\Server\ProfileRegistry
    {
        return $this->profileRegistry;
    }
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/ProfileRegistryTest.php` 然后全量 `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add src/Server/ProfileRegistry.php src/Kernel/AgentKernel.php tests/ProfileRegistryTest.php
git commit -m "feat: ProfileRegistry——profiles 目录校验/惰性 client 缓存/per-turn 解析；kernel 挂可空 registry"
```

---

### Task 3: PHP —— AgentServer：/config/profiles + /agent per-turn + 会话选择落盘

**Files:**
- Modify: `src/Server/AgentServer.php`
- Test: `tests/ServerApiTest.php`（追加）

**Interfaces:**
- Consumes: Task 1（save selection）、Task 2（ProfileRegistry）
- Produces（前端 Task 7/8/9 依赖）：
  - `AgentServer::__construct(..., Config $config, ProfileRegistry $profiles, ?ConfirmBroker $broker = null)`（构造签名变化；bin/server 由 Task 4 装配）
  - `GET /config/profiles` → `{profiles, defaultProfile}`（含 key）
  - `POST /config/profiles` body `{profiles, defaultProfile}` → `{ok:true}`；校验失败 400 `{error}`
  - `/agent` body 增可选 `profile_id`/`model`；`GET /sessions/{id}` 响应增 `meta`

- [ ] **Step 1: 写失败测试**（`tests/ServerApiTest.php` 追加；`server()` helper 改为注入 `new ProfileRegistry($kernel)` 且初值设 env fake 单档 `[['id'=>'default','name'=>'fake','provider'=>'fake','baseUrl'=>'','apiKey'=>'','models'=>['fake']]]` default 'default'——按现有 helper 的 kernel 构造方式）

```php
    public function testConfigProfilesGetPostRoundTrip(): void
    {
        $s = $this->server();
        $payload = ['profiles' => [
            ['id' => 'p-1', 'name' => 'DS', 'provider' => 'openai', 'baseUrl' => 'https://api.deepseek.com', 'apiKey' => 'sk-x', 'models' => ['deepseek-chat']],
            ['id' => 'p-2', 'name' => 'F', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['fake']],
        ], 'defaultProfile' => 'p-2'];
        $resp = $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertSame(200, $resp->getStatusCode());
        $get = json_decode((string) $s->handle(new ServerRequest('GET', 'http://localhost/config/profiles'))->getBody(), true);
        self::assertSame('p-2', $get['defaultProfile']);
        self::assertCount(2, $get['profiles']);
        self::assertSame('sk-x', $get['profiles'][0]['apiKey'], 'GET 含 key（设置页回显）');
    }

    public function testConfigProfilesRejectsInvalid(): void
    {
        $s = $this->server();
        $resp = $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode([
            'profiles' => [['id' => 'a', 'name' => '', 'provider' => 'nope', 'baseUrl' => '', 'apiKey' => '', 'models' => []]], 'defaultProfile' => 'a',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testAgentPerTurnSwitch(): void
    {
        $store = new InMemorySessionStore();
        $s = $this->server($store);
        $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode([
            'profiles' => [
                ['id' => 'f1', 'name' => 'F1', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['a']],
                ['id' => 'f2', 'name' => 'F2', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['b']],
            ], 'defaultProfile' => 'f1',
        ], JSON_THROW_ON_ERROR)));
        // fake 委托 kernel 单实例 → 两个 profile 解析到同一 fake client，脚本可切换验证调用即达：
        // 这里验证的是链路（不同 profile_id 都被接受并产出 final 事件）；client 区分由 Registry 测试守护
        foreach (['f1', 'f2'] as $pid) {
            $resp = $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
                'message' => 'hi', 'session_id' => 'sw', 'profile_id' => $pid, 'model' => null,
            ], JSON_THROW_ON_ERROR)));
            self::assertSame(200, $resp->getStatusCode());
        }
        // 选择落盘：最近一轮 f2
        $list = json_decode((string) $s->handle(new ServerRequest('GET', 'http://localhost/sessions'))->getBody(), true);
        $meta = array_values(array_filter($list['sessions'], static fn ($m) => $m['id'] === 'sw'))[0];
        self::assertSame('f2', $meta['profileId']);
    }

    public function testAgentUnknownProfileRejected(): void
    {
        $s = $this->server();
        $resp = $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
            'message' => 'hi', 'session_id' => 'x', 'profile_id' => 'ghost',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $resp->getStatusCode());
    }

    public function testLoadSessionReturnsMeta(): void
    {
        $store = new InMemorySessionStore();
        $s = $this->server($store);
        $s->handle(new ServerRequest('POST', 'http://localhost/config/profiles', [], json_encode([
            'profiles' => [['id' => 'p', 'name' => 'P', 'provider' => 'fake', 'baseUrl' => '', 'apiKey' => '', 'models' => ['m']]], 'defaultProfile' => 'p',
        ], JSON_THROW_ON_ERROR)));
        $s->handle(new ServerRequest('POST', 'http://localhost/agent', [], json_encode([
            'message' => 'hi', 'session_id' => 'm1',
        ], JSON_THROW_ON_ERROR)));
        $load = json_decode((string) $s->handle(new ServerRequest('GET', 'http://localhost/sessions/m1'))->getBody(), true);
        self::assertSame('p', $load['meta']['profileId'] ?? null);
    }
```

（若 `server()` helper 的 kernel 构造不便复用，允许在 helper 内 `PluginManager::load` 或手工注册 fake provider——与现有 helper 风格一致即可。）

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/ServerApiTest.php`
Expected: FAIL（404 / 构造签名不匹配）

- [ ] **Step 3: 实现**

① use 追加 `use PhpAgent\Server\ProfileRegistry;`（同命名空间可省）——同 namespace 免 use。

② 构造函数：`private readonly Config $config,` 之后插入 `private readonly ProfileRegistry $profiles,`（broker 参数保持最后带默认）。**同步更新类 docblock 路由清单**：加 `GET/POST /config/profiles` 两行。

③ `route()` 追加（/status 块后）：

```php
        if ($path === '/config/profiles') {
            if ($method === 'GET') {
                return $this->json(200, $this->profiles->profiles());
            }
            if ($method === 'POST') {
                $body = json_decode((string) $request->getBody(), true);
                try {
                    $this->profiles->setProfiles(
                        is_array($body['profiles'] ?? null) ? $body['profiles'] : [],
                        (string) ($body['defaultProfile'] ?? ''),
                    );
                } catch (\InvalidArgumentException $e) {
                    return $this->json(400, ['error' => $e->getMessage()]);
                }
                return $this->json(200, ['ok' => true]);
            }
        }
```

④ `handleAgent()`：`$body` 解析后、async 之前：

```php
        $profileId = isset($body['profile_id']) ? (string) $body['profile_id'] : null;
        $model = isset($body['model']) ? (string) $body['model'] : null;
        try {
            $client = $this->profiles->resolve($profileId, $model);
        } catch (\InvalidArgumentException $e) {
            return $this->json(400, ['error' => $e->getMessage()]);
        }
        $this->loop->setClient($client);
        $this->profiles->setActiveClient($client);
        $selection = ($profileId !== null || $model !== null)
            ? ['profileId' => $profileId, 'model' => $model] : null;
```

async 闭包 `use (...,$selection)`，`$this->store->save($sid, $messages, $selection);`。

⑤ `GET /sessions/{id}` GET 分支：

```php
            if ($method === 'GET') {
                $metas = $this->store->list(PHP_INT_MAX);
                $meta = null;
                foreach ($metas as $m) {
                    if ($m->id === $id) { $meta = $m->toArray(); break; }
                }
                return $this->json(200, ['id' => $id, 'messages' => $this->store->load($id), 'meta' => $meta]);
            }
```

- [ ] **Step 4: 运行确认通过 + 全量回归**

Run: `vendor/bin/phpunit tests/ServerApiTest.php` → `vendor/bin/phpunit`
Expected: PASS（此时 bin/server 尚未装配新构造参数——**全量测试仍绿**因为 ServerApiTest 的 helper 已同步；bin/server 由 Task 4 收口）

- [ ] **Step 5: 提交**

```bash
git add src/Server/AgentServer.php tests/ServerApiTest.php
git commit -m "feat: AgentServer 热更新端点 + /agent per-turn 切换 + 会话选择落盘/回传"
```

---

### Task 4: PHP —— compact 跟随当前模型 + bin/server 装配

**Files:**
- Modify: `plugins/compact/src/CompactPlugin.php`
- Modify: `bin/server`
- Test: `vendor/bin/phpunit tests/CompactPluginTest.php`（守护既有行为）

**Interfaces:**
- Consumes: Task 2 的 `AgentKernel::profileRegistry()`、Task 3 的 AgentServer 构造
- Produces: 完整可运行 server（启动 env 单档 → registry 初值；压缩跟随 activeClient）

- [ ] **Step 1: CompactPlugin 跟随修改**

`register()` 中 else 分支（compactorFactory 为 null 时），现逻辑为
`$providerId = $config->compactProvider(); 空/未注册 → $config->provider()` 然后
`$factory = fn() => $kernel->provider($providerId)`。改为：

```php
        } else {
            $providerId = $config->compactProvider();
            if ($providerId === '' || !$kernel->hasProvider($providerId)) {
                // 未显式指定压缩 provider：跟随当前对话生效的 profile client（per-turn 切换后压缩同源）
                $registry = $kernel->profileRegistry();
                $fallbackId = $config->provider();
                $factory = $registry !== null
                    ? static fn () => $registry->activeClient() ?? $kernel->provider($fallbackId)
                    : static fn () => $kernel->provider($fallbackId);
            } else {
                $factory = static fn () => $kernel->provider($providerId);
            }
        }
```

（以文件实际代码为准做最小替换；显式 compactProvider 分支行为不变。）

- [ ] **Step 2: bin/server 装配**

`bin/server` 在 `$kernel = new AgentKernel($config); PluginManager::load($kernel, $root);` 之后：

```php
// 全局凭据目录初值：来自启动 env 的单配置（default 档）；前端随后经 /config/profiles 热更新
$registry = new PhpAgent\Server\ProfileRegistry($kernel);
$registry->setProfiles([[
    'id' => 'default', 'name' => $config->provider(), 'provider' => $config->provider(),
    'baseUrl' => $config->baseUrl(), 'apiKey' => $config->apiKey(),
    'models' => [$config->model() ?: ''],
]], 'default');
$kernel->setProfileRegistry($registry);
```

`$client = $kernel->provider($config->provider());` 行保留（AgentLoop 初值），AgentServer
构造追加 `$registry` 参数：

```php
$server = new AgentServer($loop, $kernel->sessionStore(), $hooks, $root . '/public', $config, $registry, $broker);
```

文件头 `use` 区按需补 `PhpAgent\Server\ProfileRegistry`（或保持限定名如上）。

- [ ] **Step 3: 回归验证**

Run: `vendor/bin/phpunit`
Expected: 全部 PASS（CompactPluginTest 守护显式 provider 路径；若 CompactPluginTest 需要 registry 缺省路径也覆盖，确认 fallback 分支被现有用例走过）

- [ ] **Step 4: 手工冒烟（dev 侧真实链路）**

```bash
AGENT_PROVIDER=fake AGENT_SESSION_STORE=memory AGENT_SERVER_PORT=0 php bin/server &
# 等待 AGENT_LISTENING 后：
curl -s localhost:<port>/config/profiles
curl -s -X POST localhost:<port>/agent -H 'Content-Type: application/json' -d '{"message":"hi","session_id":"s1"}' | tail -1
kill %1
```

Expected: GET 返回 default 单档；/agent 产出 SSE 且 sessions 列表 meta 含 profileId=default。

- [ ] **Step 5: 提交**

```bash
git add plugins/compact/src/CompactPlugin.php bin/server
git commit -m "feat: 压缩跟随当前对话模型；bin/server 装配 ProfileRegistry（env 单档初值）"
```

---

### Task 5: Rust —— desktop.json schema v2 + 迁移 + get_profiles/save_profiles

**Files:**
- Modify: `desktop/src-tauri/src/lib.rs`
- Test: `desktop/src-tauri/src/lib.rs`（tests 模块追加/改写）

**Interfaces:**
- Consumes: 既有 `SidecarConfig`（保留为「默认档注入结构」，start_with_env 不动）
- Produces（前端 Task 7 依赖）：
  - `get_profiles() -> serde_json::Value`：`{"profiles":[...], "defaultProfile":"...", "defaultModel":"..."}`（camelCase；空配置 → `{"profiles":[],"defaultProfile":"","defaultModel":""}`）
  - `save_profiles(profiles: Vec<ProfileConfig>, default_profile: String, default_model: String) -> Result<(), String>`
  - `ProfileConfig { id, name, provider, api_key, base_url, models: Vec<String> }`（serde camelCase）
  - spawn 注入：从 profiles/defaultProfile/defaultModel 计算 `SidecarConfig` 传 `start_with_env`（语义不变）

- [ ] **Step 1: 写失败测试**（tests 模块追加/替换）

```rust
    fn sample_profiles() -> Vec<ProfileConfig> {
        vec![ProfileConfig {
            id: "p-1".into(), name: "DS".into(), provider: "openai".into(),
            api_key: "sk-1".into(), base_url: "https://api.deepseek.com".into(),
            models: vec!["deepseek-chat".into()],
        }]
    }

    #[test]
    fn persisted_state_parses_profiles_and_migrates_legacy_config() {
        // 新格式
        let st: PersistedState = serde_json::from_str(
            r#"{"projects":[],"last_session":"","profiles":[{"id":"p-1","name":"DS","provider":"openai","apiKey":"sk-1","baseUrl":"https://x","models":["m1"]}],"defaultProfile":"p-1","defaultModel":"m1"}"#,
        ).unwrap();
        assert_eq!(st.profiles.len(), 1);
        assert_eq!(st.default_profile.as_deref(), Some("p-1"));
        // 旧格式：单 config → profiles 空（迁移由 load_state/migrate 完成，序列化层面仅解析）
        let legacy: PersistedState = serde_json::from_str(
            r#"{"projects":[],"last_session":"","config":{"provider":"openai","apiKey":"sk-old","baseUrl":"https://y","model":"m-old"}}"#,
        ).unwrap();
        assert!(legacy.profiles.is_empty());
        assert!(legacy.config.is_some(), "legacy config 仍被解析以供迁移");
        let migrated = migrate_legacy(legacy);
        assert_eq!(migrated.profiles.len(), 1);
        assert_eq!(migrated.profiles[0].id, "default");
        assert_eq!(migrated.profiles[0].api_key, "sk-old");
        assert_eq!(migrated.default_profile.as_deref(), Some("default"));
        assert_eq!(migrated.default_model.as_deref(), Some("m-old"));
    }

    #[test]
    fn validate_profiles_rules() {
        assert!(validate_profiles(&sample_profiles(), "p-1").is_ok());
        assert!(validate_profiles(&[], "p-1").is_err(), "空列表拒绝");
        assert!(validate_profiles(&sample_profiles(), "nope").is_err(), "default 不在列表拒绝");
        let mut dup = sample_profiles();
        dup.push(dup[0].clone());
        assert!(validate_profiles(&dup, "p-1").is_err(), "id 重复拒绝");
        let mut bad = sample_profiles();
        bad[0].provider = "nope".into();
        assert!(validate_profiles(&bad, "p-1").is_err(), "provider 非法拒绝");
    }
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop/src-tauri && cargo test`
Expected: 编译失败（ProfileConfig 未定义）

- [ ] **Step 3: 实现**

① `ProfileConfig` 定义（lib.rs，放在 PersistedState 附近）：

```rust
/// 一个平台档：name 供 UI 显示；models 为该平台可选模型列表（首个为默认）
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "camelCase", default)]
pub struct ProfileConfig {
    pub id: String,
    pub name: String,
    pub provider: String,
    pub api_key: String,
    pub base_url: String,
    pub models: Vec<String>,
}

impl Default for ProfileConfig {
    fn default() -> Self {
        Self { id: String::new(), name: String::new(), provider: String::new(), api_key: String::new(), base_url: String::new(), models: Vec::new() }
    }
}
```

② `PersistedState`：`config: Option<SidecarConfig>` **保留**（兼容解析旧文件）+
新增：

```rust
    #[serde(default)]
    profiles: Vec<ProfileConfig>,
    #[serde(default)]
    default_profile: Option<String>,
    #[serde(default)]
    default_model: Option<String>,
```

③ 迁移函数 + load_state 收口：

```rust
/// 旧单档 config → profiles[default]（无损迁移；无 config 则保持空）
fn migrate_legacy(mut st: PersistedState) -> PersistedState {
    if st.profiles.is_empty() {
        if let Some(cfg) = st.config.take() {
            if !cfg.provider.is_empty() {
                let mut models = Vec::new();
                if !cfg.model.is_empty() {
                    models.push(cfg.model.clone());
                }
                st.profiles.push(ProfileConfig {
                    id: "default".into(), name: cfg.provider.clone(),
                    provider: cfg.provider.clone(), api_key: cfg.api_key.clone(),
                    base_url: cfg.base_url.clone(), models,
                });
                st.default_profile = Some("default".into());
                st.default_model = if cfg.model.is_empty() { None } else { Some(cfg.model) };
            }
        }
    }
    st
}
```

`load_state()` 返回前 `let st = migrate_legacy(st); st`。`save_state_full` 的 json!
把 `"config": st.config` 替换为：

```rust
        "profiles": st.profiles,
        "defaultProfile": st.default_profile,
        "defaultModel": st.default_model,
```

④ 校验 + 命令（替换 `get_config`/`save_config`/`normalize_config` 及其测试）：

```rust
fn validate_profiles(profiles: &[ProfileConfig], default_profile: &str) -> Result<(), String> {
    if profiles.is_empty() {
        return Err("profiles 不能为空".into());
    }
    let mut ids = std::collections::HashSet::new();
    for p in profiles {
        if p.id.is_empty() || !ids.insert(p.id.clone()) {
            return Err(format!("profile id 非法或重复: {}", p.id));
        }
        if !matches!(p.provider.as_str(), "openai" | "anthropic" | "fake") {
            return Err(format!("provider 必须是 openai|anthropic|fake: {}", p.id));
        }
    }
    if !profiles.iter().any(|p| p.id == default_profile) {
        return Err("defaultProfile 必须在 profiles 中".into());
    }
    Ok(())
}

/// 默认档 → sidecar env 注入结构；model 取 default_model 或该 profile 首个模型
fn default_sidecar_config(st: &PersistedState) -> Option<SidecarConfig> {
    let pid = st.default_profile.clone()?;
    let p = st.profiles.iter().find(|p| p.id == pid)?;
    if p.provider.is_empty() {
        return None;
    }
    let model = st.default_model.clone().or_else(|| p.models.first().cloned()).unwrap_or_default();
    Some(SidecarConfig { provider: p.provider.clone(), api_key: p.api_key.clone(), base_url: p.base_url.clone(), model })
}

#[tauri::command]
fn get_profiles() -> serde_json::Value {
    let st = load_state();
    serde_json::json!({
        "profiles": st.profiles,
        "defaultProfile": st.default_profile.unwrap_or_default(),
        "defaultModel": st.default_model.unwrap_or_default(),
    })
}

#[tauri::command]
fn save_profiles(profiles: Vec<ProfileConfig>, default_profile: String, default_model: String) -> Result<(), String> {
    validate_profiles(&profiles, &default_profile)?;
    let mut st = load_state();
    st.profiles = profiles;
    st.default_profile = Some(default_profile);
    st.default_model = if default_model.is_empty() { None } else { Some(default_model) };
    save_state_full(&st);
    Ok(())
}
```

⑤ `open_session_inner`：`let global_cfg = load_state().config;` 行替换为
`let global_cfg = default_sidecar_config(&load_state());`（`global_cfg.as_ref()` 传参不变）。

⑥ invoke_handler：`get_config, save_config` 替换为 `get_profiles, save_profiles`。
删除 `normalize_config` 及其测试（职责并入 validate/default 计算）。

- [ ] **Step 4: 运行确认通过 + clippy**

Run: `cd desktop/src-tauri && cargo test && cargo clippy`
Expected: PASS（clippy 与存量基线持平）

- [ ] **Step 5: 提交**

```bash
git add desktop/src-tauri/src/lib.rs
git commit -m "feat: desktop.json schema v2——profiles 目录 + 默认档，旧 config 无损迁移；get_profiles/save_profiles"
```

---

### Task 6: 前端 —— 管道层（tauri.ts / api.ts / useUiStore / SessionMeta 类型）

**Files:**
- Modify: `desktop/src/lib/tauri.ts`、`desktop/src/lib/api.ts`、`desktop/src/store/useUiStore.ts`

**Interfaces:**
- Consumes: Task 3/5 后端契约
- Produces（Task 7/8/9 依赖）：
  - `type Profile = { id: string; name: string; provider: string; apiKey: string; baseUrl: string; models: string[] }`
  - `type ProfilesPayload = { profiles: Profile[]; defaultProfile: string; defaultModel: string }`
  - `getProfiles(): Promise<ProfilesPayload>`、`saveProfiles(payload: ProfilesPayload): Promise<void>`（**移除** `getConfig`/`saveConfig`/`AgentConfig`）
  - `pushProfiles(baseUrl: string, payload: ProfilesPayload): Promise<{ ok: boolean }>`
  - `agentStream(baseUrl, message, sessionId, signal?, selection?: { profileId?: string | null; model?: string | null })`
  - `SessionMeta` 增 `profileId?: string | null; model?: string | null`；`loadSession` 返回类型增 `meta?: { profileId?: string | null; model?: string | null } | null`
  - `useUiStore` 增 `profilesPayload: ProfilesPayload | null`、`setProfilesPayload(p: ProfilesPayload | null): void`

- [ ] **Step 1: tauri.ts** —— `AgentConfig`/`getConfig`/`saveConfig` 三行替换为：

```ts
export type Profile = { id: string; name: string; provider: string; apiKey: string; baseUrl: string; models: string[] };
export type ProfilesPayload = { profiles: Profile[]; defaultProfile: string; defaultModel: string };
export const getProfiles = () => invoke<ProfilesPayload>("get_profiles");
export const saveProfiles = (payload: ProfilesPayload) =>
  invoke<void>("save_profiles", { profiles: payload.profiles, defaultProfile: payload.defaultProfile, defaultModel: payload.defaultModel });
```

- [ ] **Step 2: api.ts** —— `SessionMeta` 类型追加两个可选字段；`loadSession` 返回类型加 `meta?`；追加：

```ts
/** POST /config/profiles：向运行中的 sidecar 热更新凭据目录（旧内核 404 → 由调用方提示重启生效） */
export const pushProfiles = (baseUrl: string, payload: { profiles: unknown[]; defaultProfile: string }) =>
  json<{ ok: boolean }>(baseUrl, "/config/profiles", { method: "POST", body: JSON.stringify(payload) });
```

`agentStream` 签名与 body 构造改为：

```ts
export type TurnSelection = { profileId?: string | null; model?: string | null };
export async function* agentStream(baseUrl: string, message: string, sessionId: string, signal?: AbortSignal, selection?: TurnSelection): AsyncGenerator<AgentEvent> {
  const resp = await fetch(baseUrl + "/agent", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      message,
      session_id: sessionId,
      ...(selection?.profileId ? { profile_id: selection.profileId } : {}),
      ...(selection?.model ? { model: selection.model } : {}),
    }),
    signal,
  });
  // ……其余不变
```

- [ ] **Step 3: useUiStore** 追加：

```ts
  profilesPayload: null as ProfilesPayload | null,
  setProfilesPayload: (p: ProfilesPayload | null) => set({ profilesPayload: p }),
```

（`ProfilesPayload` 从 `../lib/tauri` import type；注释注明：全局目录缓存，App 启动与保存后刷新。）

- [ ] **Step 4: 回归门禁**

Run: `cd desktop && npx tsc -b && npm test`
Expected: **此时 SettingsDialog/App 因 getConfig 被删而编译失败属预期**（Task 7/9 收口）；本任务以 `vitest run src/lib src/store` 既有用例 + tsc 错误清单**仅限 SettingsDialog/App 引用点**为通过标准。若想避免中间态破坏，可与 Task 7 同分支连续执行后再跑全量门禁。

- [ ] **Step 5: 提交**

```bash
git add desktop/src/lib/tauri.ts desktop/src/lib/api.ts desktop/src/store/useUiStore.ts
git commit -m "feat: 前端 profiles 管道——getProfiles/saveProfiles/pushProfiles/agentStream selection"
```

---

### Task 7: 前端 —— SettingsDialog 重构为平台管理

**Files:**
- Modify: `desktop/src/components/settings/SettingsDialog.tsx`（重写）
- Modify: `desktop/src/components/settings/SettingsDialog.test.tsx`（重写）
- Modify: `desktop/src/App.tsx`（收口：删除 `applyConfig` 与旧 SettingsDialog props——Task 6 删除 `AgentConfig` 类型后此处必然编译失败，由本任务一并修复）

**Interfaces:**
- Consumes: Task 6 全部
- Produces: `<SettingsDialog testUrl: string | null />`——对话框自管开关（useUiStore）；保存 = `saveProfiles` → `setProfilesPayload` → 对每个已连接 tab `pushProfiles`（**不重启**）；`testUrl` 语义不变

- [ ] **Step 1: 重写测试**（覆盖：初始从 useUiStore.profilesPayload 回填；空目录时显示引导添加；编辑名称/模型行；添加平台；删除平台（至少保留一个禁用删除）；保存链路 saveProfiles → pushProfiles → 不调 restartSession；测试连接用当前编辑平台值）

```tsx
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { SettingsDialog } from "./SettingsDialog";
import { useUiStore } from "../../store/useUiStore";
import { useAppStore } from "../../store/useAppStore";
import * as tauri from "../../lib/tauri";
import * as api from "../../lib/api";

vi.mock("../../lib/tauri", () => ({
  getProfiles: vi.fn().mockResolvedValue({
    profiles: [{ id: "p-1", name: "DS", provider: "openai", apiKey: "sk-old", baseUrl: "https://api.deepseek.com", models: ["deepseek-chat"] }],
    defaultProfile: "p-1", defaultModel: "deepseek-chat",
  }),
  saveProfiles: vi.fn().mockResolvedValue(undefined),
  restartSession: vi.fn(),
}));
vi.mock("../../lib/api", () => ({ testConfig: vi.fn(), pushProfiles: vi.fn().mockResolvedValue({ ok: true }) }));

const payload = () => ({
  profiles: [{ id: "p-1", name: "DS", provider: "openai", apiKey: "sk-old", baseUrl: "https://api.deepseek.com", models: ["deepseek-chat"] }],
  defaultProfile: "p-1", defaultModel: "deepseek-chat",
});

describe("SettingsDialog 平台管理", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useUiStore.getState().setSettingsOpen(false);
    useUiStore.getState().setProfilesPayload(null);
    useAppStore.getState().reset();
  });

  it("回填目录并渲染平台列表", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await waitFor(() => expect(screen.getByDisplayValue("DS")).toBeInTheDocument());
    expect(screen.getByDisplayValue("deepseek-chat")).toBeInTheDocument();
  });

  it("编辑模型行并保存：saveProfiles + pushProfiles，无 restart", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    const modelInput = await screen.findByDisplayValue("deepseek-chat");
    fireEvent.change(modelInput, { target: { value: "deepseek-reasoner" } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveProfiles).toHaveBeenCalled());
    const arg = (tauri.saveProfiles as any).mock.calls[0][0];
    expect(arg.profiles[0].models).toEqual(["deepseek-reasoner"]);
    expect(api.pushProfiles).toHaveBeenCalledWith("http://127.0.0.1:1", expect.objectContaining({ defaultProfile: "p-1" }));
    expect((tauri as any).restartSession).not.toHaveBeenCalled();
  });

  it("添加平台产生第二个条目并成为可选项", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    fireEvent.click(await screen.findByText("添加平台"));
    await waitFor(() => expect(screen.getAllByDisplayValue(/https?:\/\//).length).toBeGreaterThanOrEqual(2));
  });

  it("测试连接用当前编辑平台的值", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: true, message: "连接成功", latency_ms: 88 });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    fireEvent.click(await screen.findByText("测试连接"));
    await waitFor(() => expect(screen.getByText(/连接成功（88ms）/)).toBeInTheDocument());
    expect(api.testConfig).toHaveBeenCalledWith("http://127.0.0.1:1", expect.objectContaining({ apiKey: "sk-old" }));
  });

  it("无目录时显示空态引导", () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} />);
    expect(screen.getByText(/尚未配置任何平台/)).toBeInTheDocument();
  });
});
```

（断言细节按实现微调是允许的——以「覆盖以上行为点」为准；mock 断言键名以实际组件为准。）

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npx vitest run src/components/settings/SettingsDialog.test.tsx`
Expected: FAIL

- [ ] **Step 3: 重写组件**。要点（完整结构，具体样式沿用现有 tailwind 习惯）：

- state：`const [payload, setPayload] = useState<ProfilesPayload | null>(null)`；打开时
  `useUiStore.getState().profilesPayload` 直填（不调 getProfiles——App 启动已拉取；
  若为 null 则 getProfiles 兜底）
- 编辑态：`payload.profiles` 直接映射渲染卡片列表；每个卡片：
  name Input、provider select（三值）、baseUrl Input、apiKey password Input、
  models textarea（一行一个，value=`models.join("\n")`，onChange split 过滤空行）、
  「设为默认」radio（defaultProfile）、删除按钮（profiles.length > 1 才可用）
- 「添加平台」：push `{ id: crypto.randomUUID??.slice(0,8) 或 `p-${Date.now().toString(36)}`, name: "", provider: "openai", apiKey: "", baseUrl: "", models: [] }`
- 底部：defaultModel Input（全局默认模型，placeholder「默认用所选平台首个模型」）、
  「测试连接」（`testConfig(testUrl, 当前选中编辑卡片)`）、保存
- 保存：组装 payload（过滤 id/name 空的未完成条目并至少保留一个完整平台）→
  `await saveProfiles(payload)` → `useUiStore.getState().setProfilesPayload(payload)` →
  `testUrl` 存在时 `await pushProfiles(testUrl, payload).catch(() => 提示"该项目需重启后生效")` →
  `onSaved?.(payload)` → `setOpen(false)`
- 错误显示沿用「Tauri 字符串 rejection 直接展示」模式

**注意**：旧组件的「保存后重启各项目」语义**删除**。同步修 App.tsx：删除 `applyConfig`
useCallback 整体、`restartSession`/`type AgentConfig` 相关 import、
`<SettingsDialog testUrl={...} onSaved={applyConfig} />` 改为
`<SettingsDialog testUrl={activeTab?.store.getState().connected ? activeTab.store.getState().backendUrl : null} />`。

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop && npx vitest run src/components/settings/SettingsDialog.test.tsx`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add desktop/src/components/settings desktop/src/App.tsx
git commit -m "feat: 设置页重构为平台管理——多平台 CRUD + 每平台模型列表 + 保存热更新不重启"
```

---

### Task 8: 前端 —— 对话内切换器 + 会话选择恢复

**Files:**
- Modify: `desktop/src/store/createProjectStore.ts`（selection 状态）
- Modify: `desktop/src/components/chat/Composer.tsx`（选择器 UI）
- Modify: `desktop/src/hooks/useAgentStream.ts`（发送携带 selection）
- Modify: `desktop/src/components/sessions/SessionItem.tsx`（恢复会话选择）
- Test: `desktop/src/components/chat/Composer.test.tsx`、`desktop/src/store/createProjectStore.test.tsx`（追加）

**Interfaces:**
- Consumes: Task 6 的 `agentStream` selection 参数、useUiStore.profilesPayload；Task 1 的 meta 回传
- Produces: projectStore `selection: { profileId: string | null; model: string | null }` + `setSelection`；`setActiveSession` 时重置为 null（继承默认）；发送携带 selection

- [ ] **Step 1: 写失败测试**

`createProjectStore.test.tsx` 追加：

```tsx
  it("selection 默认 null，setActiveSession 重置", () => {
    const { useProjectStore } = require("./createProjectStore");
    // 按该文件既有 store 获取方式（模块单例或工厂）适配
    const st = useProjectStore.getState ? useProjectStore.getState() : useProjectStore();
    st.setSelection({ profileId: "p-1", model: "m" });
    expect(st.getState().selection).toEqual({ profileId: "p-1", model: "m" });
    st.setActiveSession("web-x", []);
    expect(st.getState().selection).toEqual({ profileId: null, model: null });
  });
```

（以文件现有 import/单例风格为准；核心断言：初始 null、setSelection 生效、setActiveSession 重置。）

`Composer.test.tsx` 追加（完整代码；沿用该文件既有的 store 获取方式）：

```tsx
  it("渲染平台/模型选择器，改变即写入 store", async () => {
    useUiStore.getState().setProfilesPayload({
      profiles: [
        { id: "p-1", name: "DS", provider: "openai", apiKey: "sk-a", baseUrl: "", models: ["deepseek-chat", "deepseek-reasoner"] },
        { id: "p-2", name: "GLM", provider: "openai", apiKey: "sk-b", baseUrl: "", models: ["glm-4.7"] },
      ],
      defaultProfile: "p-1", defaultModel: "deepseek-chat",
    });
    useProjectStore.getState().setActiveSession("web-1", []);
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    // 默认平台 = 全局默认档 p-1；模型下拉含该平台模型
    const selects = screen.getAllByRole("combobox");
    expect(selects[0]).toHaveValue("p-1");
    expect(screen.getByRole("option", { name: "deepseek-chat" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "glm-4.7" })).toBeInTheDocument();
    // 切平台 → selection 更新且模型回落新平台首个
    fireEvent.change(selects[0], { target: { value: "p-2" } });
    expect(useProjectStore.getState().selection).toEqual({ profileId: "p-2", model: "glm-4.7" });
    // 切模型 → selection.model 更新
    fireEvent.change(selects[1], { target: { value: "deepseek-reasoner" } });
    // 注意：切平台后模型列表是 glm-4.7，这里先切回 p-1 再选 reasoner 验证 model 通道
    fireEvent.change(selects[0], { target: { value: "p-1" } });
    fireEvent.change(screen.getAllByRole("combobox")[1], { target: { value: "deepseek-reasoner" } });
    expect(useProjectStore.getState().selection.model).toBe("deepseek-reasoner");
  });
```

（import 区补 `useUiStore`；若 Composer 测试文件现有 wrapper 依赖 Radix stub 沿用之。
选择器只在 `profilesPayload?.profiles.length > 0` 时渲染——断言前必须预置目录。）

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npx vitest run src/components/chat/Composer.test.tsx src/store/createProjectStore.test.tsx`
Expected: FAIL

- [ ] **Step 3: 实现**

① createProjectStore State 增：

```ts
  selection: { profileId: string | null; model: string | null };
  setSelection: (s: { profileId: string | null; model: string | null }) => void;
```

初值 `{ profileId: null, model: null }`；`setSelection: (s) => set({ selection: s })`；
`setActiveSession` 内 `set({ selection: { profileId: null, model: null }, ... })`（既有 set 合并点追加）。

② Composer 顶部选择条（textarea 上方新增一行）：

```tsx
import { useUiStore } from "../../store/useUiStore";
// 组件内：
const profilesPayload = useUiStore((s) => s.profilesPayload);
const selection = store((s) => s.selection);
const setSelection = store((s) => s.setSelection);
const activeProfile = profilesPayload?.profiles.find((p) => p.id === (selection.profileId ?? profilesPayload?.defaultProfile));
const currentModel = selection.model ?? profilesPayload?.defaultModel ?? activeProfile?.models[0] ?? "";
const activeId = selection.profileId ?? profilesPayload?.defaultProfile ?? "";
// 渲染（profilesPayload?.profiles.length ? 下拉条 : null）：
//   <select value={activeId} onChange={e => setSelection({ profileId: e.target.value, model: profilesPayload?.profiles.find(p=>p.id===e.target.value)?.models[0] ?? null })}>…profile.name…</select>
//   <select value={currentModel} onChange={e => setSelection({ profileId: activeId, model: e.target.value })}>…activeProfile.models + 当前值兜底…</select>
```

（模型 select 的 options = activeProfile.models；当前值不在列表时插入一个临时 option 防空白。样式：`text-xs text-muted` 一行，靠左。）

③ useAgentStream：调用处追加第 5 参：

```ts
for await (const ev of agentStream(st.backendUrl, text, sessionId, ac.signal, st.selection ?? undefined)) {
```

④ SessionItem 打开会话后恢复选择：

```tsx
      const { messages } = await loadSession(store.getState().backendUrl, meta.id);
      store.getState().setActiveSession(meta.id, toChatMessages(messages));
      if (meta.profileId || meta.model) {
        store.getState().setSelection({ profileId: meta.profileId ?? null, model: meta.model ?? null });
      }
```

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop && npx vitest run src/components/chat src/store src/components/sessions && npx tsc -b`
Expected: PASS

- [ ] **Step 5: 提交**

```bash
git add desktop/src/store desktop/src/components/chat desktop/src/hooks/useAgentStream.ts desktop/src/components/sessions
git commit -m "feat: 对话内平台/模型切换器——选择随会话持久化，发送携带 profile_id/model"
```

---

### Task 9: 前端 —— App 集成（启动拉取目录 + 项目就绪推送 + applyConfig 删除）+ TitleBar 徽章

**Files:**
- Modify: `desktop/src/App.tsx`
- Modify: `desktop/src/components/layout/TitleBar.tsx`
- Test: `desktop/src/components/layout/TitleBar.test.tsx`（追加）

**Interfaces:**
- Consumes: Task 5/6/7/8
- Produces: 启动 `getProfiles` → useUiStore；`ProjectWorkspace.refresh` 成功后 fire-and-forget `pushProfiles`（幂等热更新）。`applyConfig` 的删除已在 Task 7 完成，本任务不再涉及

- [ ] **Step 1: App.tsx**

① import 区：确认无 `saveConfig`/`type AgentConfig` 残留（Task 7 已删）；加
`getProfiles, pushProfiles` 与 `useUiStore`（若无）。

② boot effect（`listProjects/getLastSession` 的 try 内最前）：

```tsx
          try {
            const p = await getProfiles();
            useUiStore.getState().setProfilesPayload(p);
          } catch { /* 目录拉取失败不阻塞启动 */ }
```

③ `ProjectWorkspace` 的 `refresh()` 成功分支追加（有 profiles 才推，静默失败）：

```tsx
  const refresh = useCallback(async () => {
    const url = store.getState().backendUrl;
    const [status, sessions] = await Promise.all([getStatus(url), listSessions(url)]);
    store.getState().setStatus(status);
    store.getState().setSessions(sessions.sessions);
    store.getState().setConnected(true);
    // 就绪后推送全局凭据目录（幂等热更新；旧内核 404 静默——设置保存时已提示重启生效）
    const p = useUiStore.getState().profilesPayload;
    if (p && p.profiles.length > 0) {
      pushProfiles(url, p).catch(() => {});
    }
  }, [store]);
```

（import `useUiStore`。）

- [ ] **Step 2: TitleBar 徽章显示当前选择**

provider 徽章内容改为：优先显示当前会话选择解析出的 `name ▸ model`，回落 `/status`：

```tsx
import { useUiStore } from "../../store/useUiStore";
// 组件内：
const profilesPayload = useUiStore((s) => s.profilesPayload);
const selection = store((s) => s.selection);
const status = store((s) => s.status);
const selProfile = profilesPayload?.profiles.find((p) => p.id === (selection.profileId ?? profilesPayload?.defaultProfile));
const selModel = selection.model ?? profilesPayload?.defaultModel ?? selProfile?.models[0] ?? "";
const badgeText = selProfile
  ? `${selProfile.name || selProfile.provider}▸${selModel || "未选"}`
  : `${status?.provider ?? "?"}/${status?.model || "未配置"}`;
const unconfigured = !selProfile && !status?.model && !(profilesPayload && profilesPayload.profiles.length > 0);
```

徽章 className 警示条件改为 `unconfigured ? "text-warning" : "text-muted"`；按钮点击行为不变。
**同时更新 `TitleBar.test.tsx`**：既有「未配置橙色」用例补 `useUiStore.getState().setProfilesPayload(null)` 前置（beforeEach 已 reset useUiStore——reset 不覆盖新字段？useUiStore 无 reset——补充手动置 null）；新增用例：`setProfilesPayload` 两平台 + store selection `{profileId:"p-2", model:"glm-4.7"}` → 徽章文本 `GLM▸glm-4.7`。

- [ ] **Step 3: 回归门禁**

Run: `cd desktop && npm test && npx tsc -b`
Expected: 全部 PASS（SettingsDialog/Composer/TitleBar/store 全链路）

- [ ] **Step 4: 提交**

```bash
git add desktop/src/App.tsx desktop/src/components/layout/TitleBar.tsx desktop/src/components/layout/TitleBar.test.tsx
git commit -m "feat: App 启动拉取目录/项目就绪推送热更新；标题栏徽章显示当前会话模型"
```

---

### Task 10: 全分支审查 + 手工验收清单

**Files:** 无新代码（审查 + 修复波）

- [ ] **Step 1: 全量门禁**：`vendor/bin/phpunit`、`cd desktop/src-tauri && cargo test && cargo clippy`、`cd desktop && npm test && npx tsc -b`
- [ ] **Step 2: 派发最终全分支审查子代理**（merge-base `b0ae621` → HEAD；携带 Minor 台账）
- [ ] **Step 3: 修复波 + 复审**
- [ ] **Step 4: 手工验收**（用户执行，清单来自 spec 验收标准 1-5）

## 验收标准（spec 同款）

1. 全新状态：设置添加两平台 → 保存 → 打开项目 → 对话下拉切换 → 消息由所选模型回答
2. 已运行 sidecar 下改 apiKey/新增平台 → 保存 → 立即生效（无重启、无断线横幅）
3. 会话 A/B 各自选择互不干扰，切回保持；重启 app 后仍恢复
4. CLI 不回归（phpunit 全绿）；旧 desktop.json 无损迁移

---

# Phase 2：模型条目结构化 + 每模型参数（Task 11-16，spec 修订 2）

> 延续同一分支 feature/multi-provider；Task 编号接续 Phase 1。全局约束、验收流程同 Phase 1。
> 设计见 spec「修订 2」节；模型参数映射现有机制（contextTokens→压缩阈值；maxTokens→请求体）。

### Task 11: PHP —— Config::maxTokensExplicit + OpenAICompatClient 条件 max_tokens

**Files:** Modify `src/Config.php`、`src/LLM/OpenAICompatClient.php`；Test `tests/HttpTransportTest.php`（沿用其本地 HTTP 服务器断言请求体的模式）

**Interfaces:** Produces `Config::maxTokensExplicit(): bool`（`isset($this->env['AGENT_MAX_TOKENS'])`）；OpenAICompatClient 请求体仅在 explicit 时含 `max_tokens`。AnthropicClient 不动（协议必传，值仍 `maxTokens()`）。

- [ ] Step 1 失败测试：HttpTransportTest 模式加两用例——client Config 带 `AGENT_MAX_TOKENS=>2048` → 请求体 JSON 含 `"max_tokens":2048`；不带 → 不含该键（其余字段照常）
- [ ] Step 2 RED → Step 3 实现（Config 方法 + body 条件插入）→ Step 4 GREEN + 全量 → Step 5 `git commit -m "feat: openai 兼容端点仅在显式设置时发送 max_tokens（Config::maxTokensExplicit）"`

### Task 12: PHP —— ProfileRegistry 模型条目（归一化/按名解析/参数 configFor/activeContextTokens）

**Files:** Modify `src/Server/ProfileRegistry.php`；Test `tests/ProfileRegistryTest.php`（追加）

**Interfaces:**
- `setProfiles` 归一化 models：string → `['name'=>s]`；array → name 必填非空，contextTokens/maxTokens 可选正整数（非法抛 InvalidArgumentException）；归一化后存储（GET 回显统一对象形态）
- `resolve`：model 名匹配条目（`name === model`），空则用 models[0] 的 name；cache key 仍 `pid|name`；记录 `lastResolved = ['profile'=>…, 'modelName'=>…]`
- `configFor(profile, entry)`：条目含 contextTokens → env `AGENT_CONTEXT_TOKENS`；含 maxTokens → `AGENT_MAX_TOKENS`（只在有值时写入 → maxTokensExplicit 才为 true）
- `activeContextTokens(): ?int`：lastResolved 条目的 contextTokens（无 lastResolved/未设置 → null）；`setProfiles` 清 lastResolved
- bin/server 初值（string models）经归一化照常工作

- [ ] Step 1 失败测试：归一化（string→对象 / 非法 name 抛 / 负数抛）；resolve 按名取参数（断言 configFor 产物——经 client 不可见，改为暴露 `resolveConfigForTesting(profileId, model): Config`? 不引入新公开面：改用「configFor private + 反射」过重——**采用**：resolve 后 `activeContextTokens()` 断言条目值；maxTokens 经 Task 11 的 explicit 链路由 HttpTransport 集成或 `$r->resolve('p1','m2')` 后 activeContextTokens 组合断言；测试覆盖：string 兼容、按名解析、缓存键不变、setProfiles 清 lastResolved
- [ ] Step 2 RED → Step 3 实现 → Step 4 GREEN + 全量 → Step 5 `git commit -m "feat: ProfileRegistry 模型条目化——按名解析参数 configFor + activeContextTokens"`

### Task 13: PHP —— CompactPlugin 跟随每模型 contextTokens

**Files:** Modify `plugins/compact/src/CompactPlugin.php`；Test `tests/CompactPluginTest.php`（追加）

**Interfaces:** 跟随分支 factory 的 contextTokens 参数改为压缩时刻求值：`$kernel->profileRegistry()?->activeContextTokens() ?? $contextTokens`（activeClient 同源逻辑不变；显式 provider 分支不动）。测试：registry 含 contextTokens=500 的条目 → resolve 后触发 factory → Compactor 收到 500；未解析 → 全局值。

- [ ] TDD：Step 1 失败测试（现有测试构造注入 factory 的路径不受影响）→ Step 2 RED → Step 3 实现 → Step 4 GREEN + 全量 → Step 5 `git commit -m "feat: 自动压缩阈值跟随当前对话模型的 contextTokens"`

### Task 14: Rust —— ModelEntry 双格式反序列化 + 迁移

**Files:** Modify `desktop/src-tauri/src/lib.rs`；Test 同文件 tests 模块（追加/改写）

**Interfaces:**
- `ModelEntry { name: String, context_tokens: Option<u64>, max_tokens: Option<u64> }`（camelCase rename）
- `ProfileConfig.models: Vec<ModelEntry>`，`#[serde(default, deserialize_with = "deserialize_models")]`——数组项为字符串（旧格式）或对象（新格式）均可；**序列化恒为对象**
- `default_sidecar_config`：env model 取 `p.models.first().map(|m| m.name.clone())`
- `validate_profiles`：条目 name trim 非空校验
- 既有测试改写（models 字符串 → 对象）+ 新增：字符串项解析、混合数组、name 空 → Err

- [ ] TDD → `cargo test && cargo clippy` → `git commit -m "feat: Rust 模型条目化——ModelEntry 双格式解析（旧字符串无损兼容）"`

### Task 15: 前端 —— ModelEntry 类型 + 设置页动态模型列表 + Composer/TitleBar 适配

**Files:** Modify `desktop/src/lib/tauri.ts`、`desktop/src/components/settings/SettingsDialog.tsx(.test.tsx)`、`desktop/src/components/chat/Composer.tsx(.test.tsx)`、`desktop/src/components/layout/TitleBar.tsx(.test.tsx)`

**Interfaces:**
- `type ModelEntry = { name: string; contextTokens?: number | null; maxTokens?: number | null }`；`Profile.models: ModelEntry[]`
- SettingsDialog 平台卡片：模型区改动态列表——每条一行（name Input / contextTokens 数字输入 placeholder「上下文 128000」/ maxTokens 数字输入 placeholder「输出默认」/ 删除按钮）+「添加模型」；clonePayload 深拷贝条目；保存组装过滤空 name、数字字段空串→null；runTest 目标模型取默认档条目 name
- Composer：`models = activeProfile?.models.map(m => m.name) ?? []`（modelTemp/includes/首模型回落同步改 `.name`）；TitleBar `selModel` 回落 `.models[0]?.name ?? ""`
- 测试适配 + 新增：动态添加/删除模型行；参数输入回填；Composer 选项按 name

- [ ] TDD → `npm test && npx tsc -b` 全绿 → `git commit -m "feat: 设置页模型动态条目化（每模型 contextTokens/maxTokens）；对话切换与徽章适配 name 字段"`

### Task 16: 增量终审（05eddb6..HEAD）+ 修复波

- 全量三套件门禁 → 派发终审子代理（范围=Phase 2 diff；携带 Phase 1 遗留 follow-ups 清单）→ 修复波 → 复审 → 用户手工验收 → 合并
