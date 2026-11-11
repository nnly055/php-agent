<?php

declare(strict_types=1);

namespace PhpAgent\Kernel;

use PhpAgent\Agent\ToolRegistry;
use PhpAgent\Command\Command;
use PhpAgent\Config;
use PhpAgent\Hook\AgentHook;
use PhpAgent\LLM\LLMClient;
use PhpAgent\Session\SessionStore;
use PhpAgent\Session\Store\InMemorySessionStore;
use PhpAgent\Agent\Tool;

/**
 * 插件注册容器 + 服务定位器（刻意不做完整 DI，够用即可）。
 *
 * 覆盖语义：后注册者覆盖同 id 的 provider / 同名工具 / 会话存储——
 * 这是"切换不同持久化方式""替换内置实现"的实现机制。
 */
final class AgentKernel
{
    private ToolRegistry $tools;

    /** @var array<string, \Closure(AgentKernel): LLMClient> */
    private array $providerFactories = [];

    /** @var array<string, LLMClient> */
    private array $providerInstances = [];

    private ?SessionStore $sessionStore = null;

    /** @var AgentHook[] */
    private array $hooks = [];

    /** @var array<string, Command> */
    private array $commands = [];

    /** @var array<string, string> 插件名 => 版本（加载时记录，/plugins 展示用） */
    private array $pluginInfo = [];

    public function __construct(private readonly Config $config)
    {
        $this->tools = new ToolRegistry();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function toolRegistry(): ToolRegistry
    {
        return $this->tools;
    }

    public function registerTool(Tool $tool): void
    {
        if (in_array($tool->name(), $this->tools->names(), true)) {
            fwrite(STDERR, "⚠ 工具 '{$tool->name()}' 被插件重复注册，后者覆盖\n");
        }
        $this->tools->register($tool);
    }

    public function registerProvider(string $id, \Closure $factory): void
    {
        $this->providerFactories[$id] = $factory;
        unset($this->providerInstances[$id]); // 覆盖时失效缓存
    }

    public function hasProvider(string $id): bool
    {
        return isset($this->providerFactories[$id]);
    }

    /** @return string[] */
    public function providerIds(): array
    {
        return array_keys($this->providerFactories);
    }

    /** 惰性实例化并缓存 */
    public function provider(string $id): LLMClient
    {
        if (isset($this->providerInstances[$id])) {
            return $this->providerInstances[$id];
        }
        $factory = $this->providerFactories[$id]
            ?? throw new \RuntimeException(
                "未注册的 LLM provider '{$id}'（可用: " . implode(', ', $this->providerIds()) . '）'
            );
        return $this->providerInstances[$id] = $factory($this);
    }

    public function setSessionStore(SessionStore $store): void
    {
        $this->sessionStore = $store;
    }

    public function sessionStore(): SessionStore
    {
        return $this->sessionStore ??= new InMemorySessionStore();
    }

    public function addHook(AgentHook $hook): void
    {
        $this->hooks[] = $hook; // AgentLoop 接线在 Phase 2
    }

    /** @return AgentHook[] */
    public function hooks(): array
    {
        return $this->hooks;
    }

    public function registerCommand(string $name, Command $cmd): void
    {
        $this->commands[$name] = $cmd;
    }

    public function command(string $name): ?Command
    {
        return $this->commands[$name] ?? null;
    }

    /** @return array<string, Command> */
    public function commands(): array
    {
        return $this->commands;
    }

    public function notePlugin(string $name, string $version): void
    {
        $this->pluginInfo[$name] = $version;
    }

    /** @return array<string, string> */
    public function plugins(): array
    {
        return $this->pluginInfo;
    }
}
