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
    /** @var list<array{id:string,name:string,provider:string,baseUrl:string,apiKey:string,models:array<int,array{name:string,contextTokens?:int,maxTokens?:int}>}> 归一化后的列表，profiles() 回显统一对象形态 */
    private array $profiles = [];
    /** @var array<string, array{id:string,name:string,provider:string,baseUrl:string,apiKey:string,models:array<int,array{name:string,contextTokens?:int,maxTokens?:int}>}> id → profile，resolve 查找用 */
    private array $byId = [];
    private string $defaultProfileId = '';
    /** @var array<string, LLMClient> */
    private array $clients = [];
    private ?LLMClient $activeClient = null;
    /** @var array{profile:array, modelName:string}|array{} 最近一次 resolve 的生效模型（压缩跟随用，Task 13）；setProfiles/kernel 回退时清空 */
    private array $lastResolved = [];

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
        // 归一化 models：旧格式字符串 → 对象；条目 name 必填，contextTokens/maxTokens 可选正整数，
        // maxTokens 另有 ≥1024 下限（与 Config::maxTokens 对齐）
        $normalized = [];
        foreach ($profiles as $p) {
            $id = (string) ($p['id'] ?? '');
            $p['models'] = array_map(static function ($m) use ($id) {
                if (is_string($m)) {
                    return ['name' => trim($m)]; // 旧格式字符串 → 对象
                }
                $name = trim((string) ($m['name'] ?? ''));
                if ($name === '') {
                    throw new \InvalidArgumentException("profile {$id} 的模型条目缺少 name");
                }
                $entry = ['name' => $name];
                foreach (['contextTokens', 'maxTokens'] as $k) {
                    if (isset($m[$k]) && $m[$k] !== null && $m[$k] !== '') {
                        $v = filter_var($m[$k], FILTER_VALIDATE_INT);
                        if ($v === false || $v <= 0) {
                            throw new \InvalidArgumentException("模型 {$name} 的 {$k} 必须是正整数");
                        }
                        // maxTokens 显式设置但低于 1024 → 显式报错（与 Config::maxTokens 的 max(1024, …)
                        // 下限对齐），避免校验通过却被静默抬高到 1024；contextTokens 不加下限，>0 即可
                        if ($k === 'maxTokens' && $v < 1024) {
                            throw new \InvalidArgumentException("模型 {$name} 的 maxTokens 不能低于 1024");
                        }
                        $entry[$k] = $v;
                    }
                }
                return $entry;
            }, is_array($p['models'] ?? null) ? $p['models'] : []);
            $normalized[] = $p;
        }
        $this->profiles = $normalized;
        $this->byId = [];
        foreach ($normalized as $p) {
            $this->byId[(string) $p['id']] = $p; // id 索引，resolve 查找用
        }
        $this->defaultProfileId = $defaultProfileId;
        $this->clients = []; // 凭据可能已变，清缓存
        $this->lastResolved = []; // profiles 重设后生效模型失效
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
            // 未配置 profiles 且未指定 profileId：回退 kernel 当前配置的 provider（无模型条目参数）
            $this->lastResolved = [];
            return $this->kernelClient();
        }
        $profile = $this->byId[$pid]
            ?? throw new \InvalidArgumentException("未知 profile: {$pid}");
        // 按名匹配模型条目：显式名不在目录中 → 仍按该名解析（无每模型参数）；未指定 → 回落首个模型名
        $models = $profile['models'] ?? [];
        $entry = null;
        if ($model !== null && $model !== '') {
            foreach ($models as $m) {
                if (($m['name'] ?? '') === $model) {
                    $entry = $m;
                    break;
                }
            }
            if ($entry === null) {
                $entry = ['name' => $model]; // 未收录名：无 contextTokens/maxTokens 参数
            }
        } else {
            $entry = $models[0] ?? ['name' => ''];
            $model = (string) $entry['name']; // 回落首个模型名
        }
        $this->lastResolved = ['profile' => $profile, 'modelName' => $model]; // 压缩跟随用（Task 13）
        $key = $pid . '|' . $model;
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }
        $client = match ($profile['provider']) {
            'fake' => $this->kernel?->provider('fake') ?? new FakeLLMClient([]),
            'anthropic' => new AnthropicClient($this->configFor($profile, $entry)),
            default => new OpenAICompatClient($this->configFor($profile, $entry)),
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

    /** 按 profile + 模型条目构造 Config；条目参数只在有值时写入（maxTokensExplicit 判定依赖 isset 语义） */
    private function configFor(array $profile, array $entry): Config
    {
        $env = [
            'AGENT_PROVIDER' => (string) $profile['provider'],
            'AGENT_API_KEY' => (string) ($profile['apiKey'] ?? ''),
            'AGENT_BASE_URL' => (string) ($profile['baseUrl'] ?? ''),
            'AGENT_MODEL' => (string) $entry['name'],
        ];
        // 只在有值时写入：maxTokensExplicit/contextTokens 判定依赖 isset 语义
        if (isset($entry['contextTokens']) && $entry['contextTokens'] > 0) {
            $env['AGENT_CONTEXT_TOKENS'] = (string) $entry['contextTokens'];
        }
        if (isset($entry['maxTokens']) && $entry['maxTokens'] > 0) {
            $env['AGENT_MAX_TOKENS'] = (string) $entry['maxTokens'];
        }
        return Config::fromArray($env);
    }

    /** 当前对话生效模型的 contextTokens（压缩跟随用）；未解析或未设置 → null */
    public function activeContextTokens(): ?int
    {
        $t = $this->lastResolved['profile']['models'] ?? [];
        foreach ($t as $m) {
            if (($m['name'] ?? '') === ($this->lastResolved['modelName'] ?? '')) {
                return isset($m['contextTokens']) && $m['contextTokens'] > 0 ? (int) $m['contextTokens'] : null;
            }
        }
        return null;
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
