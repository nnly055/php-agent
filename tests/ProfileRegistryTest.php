<?php
declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\ProfileRegistry;
use PhpAgent\LLM\LLMClient;
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
        // Task 12：旧格式字符串模型在存储前归一化为统一对象形态，GET 回显即对象
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->profiles(), 'p-1');
        $expected = $this->profiles();
        $expected[0]['models'] = [['name' => 'deepseek-chat'], ['name' => 'deepseek-reasoner']];
        $expected[1]['models'] = [['name' => 'glm-4.7']];
        self::assertSame(['profiles' => $expected, 'defaultProfile' => 'p-1'], $r->profiles());
    }

    /** Task 12：模型条目形态的 profiles（对象含每模型参数） */
    private function entryProfiles(): array
    {
        return [
            ['id' => 'p-1', 'name' => 'DeepSeek', 'provider' => 'openai', 'baseUrl' => 'https://api.deepseek.com', 'apiKey' => 'sk-a',
                'models' => [
                    ['name' => 'deepseek-chat', 'contextTokens' => 65536, 'maxTokens' => 8192],
                    ['name' => 'deepseek-reasoner'],
                ]],
            ['id' => 'p-2', 'name' => 'GLM', 'provider' => 'anthropic', 'baseUrl' => 'https://open.bigmodel.cn', 'apiKey' => 'sk-b', 'models' => ['glm-4.7']],
        ];
    }

    public function testSetProfilesRejectsModelEntryMissingName(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $p = [['id' => 'x', 'name' => 'X', 'provider' => 'openai', 'baseUrl' => '', 'apiKey' => '', 'models' => [['name' => '  ']]]];
        $this->expectException(\InvalidArgumentException::class);
        // 错误带 profile id，便于多平台目录下定位是哪一条
        $this->expectExceptionMessage('profile x 的模型条目缺少 name');
        $r->setProfiles($p, 'x');
    }

    public function testSetProfilesRejectsNonPositiveModelParams(): void
    {
        $r = new ProfileRegistry($this->kernel());
        // contextTokens/maxTokens 必须是正整数：0、负数、非法字符串都拒绝
        foreach ([['contextTokens', 0], ['contextTokens', -5], ['maxTokens', -1], ['contextTokens', 'abc']] as [$k, $v]) {
            try {
                $p = [['id' => 'x', 'name' => 'X', 'provider' => 'openai', 'baseUrl' => '', 'apiKey' => '', 'models' => [['name' => 'm', $k => $v]]]];
                $r->setProfiles($p, 'x');
                self::fail("{$k}={$v} 应抛 InvalidArgumentException");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("{$k} 必须是正整数", $e->getMessage());
            }
        }
    }

    public function testSetProfilesRejectsMaxTokensBelowFloor(): void
    {
        // maxTokens 显式设置但 < 1024 → 显式报错（与 Config::maxTokens 的 max(1024, …) 下限对齐），
        // 而非校验通过后被静默抬高到 1024
        $r = new ProfileRegistry($this->kernel());
        $p = [['id' => 'x', 'name' => 'X', 'provider' => 'openai', 'baseUrl' => '', 'apiKey' => '', 'models' => [['name' => 'm', 'maxTokens' => 500]]]];
        try {
            $r->setProfiles($p, 'x');
            self::fail('maxTokens=500 应抛 InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('maxTokens 不能低于 1024', $e->getMessage());
        }
    }

    public function testSetProfilesAcceptsMaxTokensAtFloorAndMinimalContextTokens(): void
    {
        // 下限值 maxTokens=1024 通过；contextTokens 无下限，1 即通过
        $r = new ProfileRegistry($this->kernel());
        $p = [['id' => 'x', 'name' => 'X', 'provider' => 'openai', 'baseUrl' => '', 'apiKey' => '', 'models' => [['name' => 'm', 'contextTokens' => 1, 'maxTokens' => 1024]]]];
        $r->setProfiles($p, 'x');
        self::assertSame([['name' => 'm', 'contextTokens' => 1, 'maxTokens' => 1024]], $r->profiles()['profiles'][0]['models']);
    }

    public function testActiveContextTokensFollowsResolvedEntry(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->entryProfiles(), 'p-1');
        self::assertNull($r->activeContextTokens(), '未 resolve 前无生效模型 → null');
        $r->resolve('p-1', 'deepseek-chat');
        self::assertSame(65536, $r->activeContextTokens());
        $r->resolve('p-1', 'deepseek-reasoner'); // 条目无 contextTokens
        self::assertNull($r->activeContextTokens(), '条目未设置 contextTokens → null');
    }

    public function testSetProfilesClearsActiveContextTokens(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->entryProfiles(), 'p-1');
        $r->resolve('p-1', 'deepseek-chat');
        self::assertSame(65536, $r->activeContextTokens());
        $r->setProfiles($this->entryProfiles(), 'p-1'); // 重设 profiles 清 lastResolved
        self::assertNull($r->activeContextTokens());
    }

    public function testExplicitUnknownModelStillResolves(): void
    {
        // 显式模型名不在目录中：仍按该名解析（无每模型参数），不抛异常
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->entryProfiles(), 'p-1');
        $client = $r->resolve('p-1', 'not-in-catalog');
        self::assertInstanceOf(LLMClient::class, $client);
        self::assertSame($client, $r->resolve('p-1', 'not-in-catalog'), '未收录名同样按 pid|name 缓存');
        self::assertNull($r->activeContextTokens(), '未收录名无条目参数 → null');
    }

    public function testResolveWithMaxTokensEntryCachesByName(): void
    {
        // maxTokens 条目 + 显式模型名：解析正常且缓存键不变（AGENT_MAX_TOKENS 经 Task 11 explicit 链路发送）
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->entryProfiles(), 'p-1');
        $first = $r->resolve('p-1', 'deepseek-chat');
        self::assertSame(65536, $r->activeContextTokens(), 'maxTokens 条目解析后 contextTokens 仍可读');
        self::assertSame($first, $r->resolve('p-1', 'deepseek-chat'), '同键缓存同实例');
        self::assertNotSame($first, $r->resolve('p-1', 'deepseek-reasoner'), '不同 model 不同实例');
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

    // ── 会话内切换（CLI）：resolvedSelection + configFor 全局 maxTokens 兜底 ──

    public function testResolvedSelectionFollowsExplicitResolve(): void
    {
        $r = new ProfileRegistry($this->kernel());
        $r->setProfiles($this->profiles(), 'p-1');
        $r->resolve('p-2', null); // 未指定模型 → 回落首个模型名
        self::assertSame(['profileId' => 'p-2', 'model' => 'glm-4.7'], $r->resolvedSelection());
        $r->resolve('p-1', 'deepseek-reasoner');
        self::assertSame(['profileId' => 'p-1', 'model' => 'deepseek-reasoner'], $r->resolvedSelection());
    }

    public function testResolvedSelectionNullOnKernelFallback(): void
    {
        // 未配置 profiles → kernel 回退路径无目录选择，selection 必须为 null
        // （否则未做过 /model 选择的会话会被写脏 meta）
        $r = new ProfileRegistry($this->kernel());
        $r->resolve(null, null);
        self::assertNull($r->resolvedSelection());
    }

    /** configFor 是 resolve 的私有映射；经反射读客户端 Config 断言继承语义（线上字节级由 wire 矩阵钉住） */
    private function clientConfig(LLMClient $client): Config
    {
        $prop = (new \ReflectionClass($client))->getProperty('config');
        return $prop->getValue($client);
    }

    public function testConfigForInheritsGlobalMaxTokens(): void
    {
        // 回归点：CLI 启动 client 改经 registry 后，.env 显式 AGENT_MAX_TOKENS 不得被静默丢掉
        $k = new AgentKernel(Config::fromArray(['AGENT_PROVIDER' => 'openai', 'AGENT_MAX_TOKENS' => '16384', 'AGENT_SESSION_STORE' => 'memory']));
        $r = new ProfileRegistry($k);
        $p = [['id' => 'p', 'name' => 'X', 'provider' => 'openai', 'baseUrl' => 'https://x', 'apiKey' => 'sk', 'models' => ['m1']]];
        $r->setProfiles($p, 'p');
        $cfg = $this->clientConfig($r->resolve('p', 'm1'));
        self::assertSame(16384, $cfg->maxTokens());
        self::assertTrue($cfg->maxTokensExplicit(), '继承的全局 maxTokens 必须保持 explicit 语义（openai 端才发 max_tokens）');
    }

    public function testConfigForEntryExplicitMaxTokensWins(): void
    {
        $k = new AgentKernel(Config::fromArray(['AGENT_PROVIDER' => 'openai', 'AGENT_MAX_TOKENS' => '16384', 'AGENT_SESSION_STORE' => 'memory']));
        $r = new ProfileRegistry($k);
        $p = [['id' => 'p', 'name' => 'X', 'provider' => 'openai', 'baseUrl' => 'https://x', 'apiKey' => 'sk', 'models' => [['name' => 'm1', 'maxTokens' => 4096]]]];
        $r->setProfiles($p, 'p');
        $cfg = $this->clientConfig($r->resolve('p', 'm1'));
        self::assertSame(4096, $cfg->maxTokens());
    }

    public function testConfigForKeepsImplicitMaxTokensWhenGlobalUnset(): void
    {
        // 全局未设 AGENT_MAX_TOKENS → 不发 max_tokens（openai 兼容端现行为不变）
        $k = new AgentKernel(Config::fromArray(['AGENT_PROVIDER' => 'openai', 'AGENT_SESSION_STORE' => 'memory']));
        $r = new ProfileRegistry($k);
        $p = [['id' => 'p', 'name' => 'X', 'provider' => 'openai', 'baseUrl' => 'https://x', 'apiKey' => 'sk', 'models' => ['m1']]];
        $r->setProfiles($p, 'p');
        $cfg = $this->clientConfig($r->resolve('p', 'm1'));
        self::assertFalse($cfg->maxTokensExplicit());
    }
}
