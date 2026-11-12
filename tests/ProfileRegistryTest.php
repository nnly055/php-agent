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
