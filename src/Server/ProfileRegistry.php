<?php

declare(strict_types=1);

namespace PhpAgent\Server;

use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\LLM\AnthropicClient;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\LLMClient;
use PhpAgent\LLM\OpenAICompatClient;

/**
 * 多平台凭据注册表：profiles（平台 × 模型目录）→ 惰性构造并缓存 LLM client。
 * per-turn 切换：/agent 携带 profile_id/model → resolve() → AgentLoop::setClient。
 * 未配置 profiles 时回退 kernel 当前配置的 provider（常规单凭据路径）。
 * key 只在本对象与 desktop.json 中存在；本类不做任何日志/审计输出。
 */
final class ProfileRegistry
{
    /** @var list<array{id:string,name:string,provider:string,baseUrl:string,apiKey:string,models:array<int,string>}> 原始列表，profiles() 原样返回 */
    private array $profiles = [];
    /** @var array<string, array{id:string,name:string,provider:string,baseUrl:string,apiKey:string,models:array<int,string>}> id → profile，resolve 查找用 */
    private array $byId = [];
    private string $defaultProfileId = '';
    /** @var array<string, LLMClient> */
    private array $clients = [];
    private ?LLMClient $activeClient = null;

    public function __construct(private ?AgentKernel $kernel = null)
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
        $this->byId = [];
        foreach ($profiles as $p) {
            $this->byId[(string) $p['id']] = $p; // id 索引，resolve 查找用
        }
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
        if ($pid === '') {
            // 未配置 profiles 且未指定 profileId：回退 kernel 当前配置的 provider
            return $this->kernelClient();
        }
        $profile = $this->byId[$pid]
            ?? throw new \InvalidArgumentException("未知 profile: {$pid}");
        $model = $model !== null && $model !== '' ? $model : (string) ($profile['models'][0] ?? '');
        $key = $pid . '|' . $model;
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }
        $client = match ($profile['provider']) {
            'fake' => $this->kernel?->provider('fake') ?? new FakeLLMClient([]),
            'anthropic' => new AnthropicClient($this->configFor($profile, $model)),
            default => new OpenAICompatClient($this->configFor($profile, $model)),
        };
        return $this->clients[$key] = $client;
    }

    /** 无 profiles 时按 kernel 配置构造 client（构造结果同样缓存） */
    private function kernelClient(): LLMClient
    {
        $config = $this->kernel?->config() ?? Config::fromArray([]);
        $provider = $config->provider();
        $key = 'kernel|' . $provider; // 与 profile 缓存键（pid|model）空间隔离
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }
        $client = match ($provider) {
            'fake' => $this->kernel?->provider('fake') ?? new FakeLLMClient([]),
            'anthropic' => new AnthropicClient($config),
            default => new OpenAICompatClient($config),
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
