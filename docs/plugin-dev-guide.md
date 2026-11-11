# PHP Agent 插件开发指南

> 给第三方开发者的接入手册：如何为 PHP Agent 写一个插件（以「新增一种会话持久化」为例），
> 以及如何分发给他人使用。参考实现：[plugins/mysql-store/](../plugins/mysql-store/)（MySQL 持久化，
> 把 `plugin.php.disabled` 重命名为 `plugin.php` 即启用）。

## 1. 插件能做什么

| 扩展点 | 方法 | 说明 |
|---|---|---|
| 工具 | `registerTool(Tool)` | 模型通过 function calling 调用 |
| LLM Provider | `registerProvider(id, factory)` | 新增/覆盖 LLM 接入 |
| **会话持久化** | `setSessionStore(SessionStore)` | 自定义存储后端 |
| 斜杠命令 | `registerCommand(name, cmd)` | CLI 里 `/xxx` 触发 |
| 生命周期钩子 | `addHook(AgentHook)` | 权限确认、审计、上下文注入等 |

## 2. 最小接入步骤（项目内使用）

```
plugins/<你的插件名>/
├── plugin.php            ← 返回插件类名（被发现即自动加载）
└── src/
    ├── MySessionStore.php   ← 实现 PhpAgent\Session\SessionStore
    └── MyStorePlugin.php    ← 实现 PhpAgent\Kernel\Plugin
```

**plugin.php**

```php
<?php
require_once __DIR__ . '/src/MySessionStore.php';
require_once __DIR__ . '/src/MyStorePlugin.php';
return MyStore\MyStorePlugin::class;
```

**MyStorePlugin.php**

```php
<?php
namespace MyStore;
use PhpAgent\Kernel\{AgentKernel, Plugin};

final class MyStorePlugin implements Plugin
{
    public function name(): string { return 'my-store'; }
    public function version(): string { return '1.0.0'; }

    public function register(AgentKernel $kernel): void
    {
        // 项目插件晚于内置插件加载，setSessionStore 直接覆盖内置实现
        $kernel->setSessionStore(new MySessionStore(
            (string) (getenv('MY_STORE_DSN') ?: ''), // 插件自带配置，读自己的环境变量
        ));
    }
}
```

**MySessionStore.php** —— 实现四个方法（契约见 `src/Session/SessionStore.php`）：

```php
public function list(int $limit = 50): array;           // SessionMeta[]，按 updatedAt 倒序
public function load(string $sessionId): array;         // 归一化消息数组；不存在返回 []
public function save(string $sessionId, array $messages): void;  // 整段原子保存
public function delete(string $sessionId): void;
```

约定：

- 存储载荷用 `["schemaVersion" => 1, "messages" => [...]]` 的 JSON（`messages` 是内核
  归一化格式，带 schemaVersion 便于将来迁移）
- 标题用 `SessionMeta::deriveTitle($messages)` 从首条用户输入派生，首次保存后固定
- `load()` 对不存在/损坏的会话返回 `[]`，不要抛异常
- 完成后无需注册任何东西——`plugins/*/plugin.php` 会被自动发现；启动横幅的
  「会话存储」一行应显示你的类名

快速自验：

```bash
printf '你好\n/sessions\n/resume\n/exit\n' | php bin/agent
# 第二次运行 /sessions 应能看到刚才的会话
```

## 3. 快速起步：脚手架

```bash
php bin/agent --plugin:new my-tool   # 生成含示例工具+命令的骨架，改掉示例即可
```

## 4. 分发给其他用户

### 方式 A：composer 包（推荐）

1. 你的包里放插件类（composer autoload 能加载到即可），在**你的** composer.json 声明：

```json
{
    "extra": {
        "php-agent": { "plugins": ["Vendor\\PhpAgent\\MyStorePlugin"] }
    }
}
```

2. 用户只需：

```bash
composer require vendor/php-agent-my-store
AGENT_SESSION_STORE 不用改   # 插件自动覆盖内置存储
```

发现机制读取 `vendor/composer/installed.json`，无需用户改任何代码或配置文件。

### 方式 B：让用户拷目录

用户把你的 `plugins/<name>/` 目录整个拷进自己的 php-agent 目录即可（plugin.php 在
就生效）。不想要了删目录或重命名 `plugin.php → plugin.php.disabled`。

## 5. 加载顺序与覆盖语义

```
内置插件（SessionPlugin 等）
  → composer 包插件
    → 项目 plugins/ 插件
```

后注册者**覆盖**同 id 注册：composer 包可以覆盖内置（如替换存储），项目插件可以覆盖
composer 包（如给某存储加缓存装饰层）。`AGENT_PLUGINS=off` 只加载内置；
`AGENT_PLUGINS=a,b` 按插件 name 白名单过滤后两层。

## 6. 陷阱与约定

- 插件代码 = 用户机器上的任意 PHP 代码，只装可信来源的插件
- 不要在 `register()` 里做重活（连库、网络请求）——把连接做成惰性，首次 `save/load` 才建连
- 插件异常会传播给用户：构造函数/连接失败时抛带明确提示的异常
- 表名、环境变量加插件前缀（如 `MY_STORE_*`），避免与内核 `AGENT_*` 冲突
