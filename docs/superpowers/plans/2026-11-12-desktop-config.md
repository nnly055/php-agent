# 桌面端 API 配置功能 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 桌面端可配置全局 provider/API Key/Base URL/Model，保存后重启 sidecar 生效，标题栏徽章成为配置入口并可测试连接。

**Architecture:** 配置持久化在 `~/.php-agent/desktop.json` 顶层 `config` 字段（0600）；Rust 壳启动 sidecar 时注入 `AGENT_*` 环境变量（空值不注入，server 模式 shell 权威 → 覆盖 bundle 内只读 .env）；保存后前端对每个运行中 tab 调 `restart_session`（签名改为返回新 `{sessionId, port}`，同时修复重连后 tab 绑定失效的既有 bug）。测试连接走 PHP 新增 `POST /config/test`（临时 Config 实例化现成 LLM client 发 ping）。

**Tech Stack:** PHP 8.1+（phpunit）、Tauri 2 + Rust（cargo test）、React + zustand + vitest。不新增任何依赖。

**Spec:** `docs/superpowers/specs/2026-11-12-desktop-config-design.md`

## Global Constraints

- 不新增任何依赖（composer / cargo / npm 均不动）
- API key 不出现在 `/status` 响应、日志、审计、任何 UI 明文中
- 注释与提交信息用中文，遵循仓库现有风格（`feat:`/`fix:`/`test:` 前缀）
- key 存储文件权限 0600（unix）
- Rust `SidecarConfig` 序列化用 `#[serde(rename_all = "camelCase")]`（前端 `AgentConfig` 字段为 camelCase）
- provider 合法值固定为 `openai | anthropic | fake`，三端一致校验
- 测试命令：`vendor/bin/phpunit`（仓库根）、`cargo test`（`desktop/src-tauri/`，需要本机 php）、`npm test`（`desktop/`）

---

### Task 1: PHP `Config::fromArray()` 静态工厂

**Files:**
- Modify: `src/Config.php`（`load()` 方法之后、`provider()` 之前）
- Test: `tests/ConfigTest.php`

**Interfaces:**
- Consumes: 私有构造函数 `new self(array $env)`（现有）
- Produces: `Config::fromArray(array $env): self` —— `$env` 为完整 `AGENT_*` 键值映射；Task 2 的 `/config/test` 依赖

- [ ] **Step 1: 写失败测试**

在 `tests/ConfigTest.php` 类内追加两个方法：

```php
    public function testFromArrayBuildsIndependentConfig(): void
    {
        $c = Config::fromArray([
            'AGENT_PROVIDER' => 'anthropic',
            'AGENT_API_KEY' => 'sk-test',
            'AGENT_BASE_URL' => 'https://example.com/',
            'AGENT_MODEL' => 'claude-sonnet-4-5',
            'AGENT_MAX_TOKENS' => '16',
        ]);
        self::assertSame('anthropic', $c->provider());
        self::assertSame('sk-test', $c->apiKey());
        self::assertSame('https://example.com', $c->baseUrl());
        self::assertSame('claude-sonnet-4-5', $c->model());
        self::assertSame(16, $c->maxTokens());
    }

    public function testFromArrayEmptyFallsBackToDefaults(): void
    {
        $c = Config::fromArray([]);
        self::assertSame('openai', $c->provider());
        self::assertSame('https://api.openai.com/v1', $c->baseUrl());
        self::assertSame('', $c->model());
    }
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/ConfigTest.php`
Expected: FAIL，`Call to undefined method PhpAgent\Config::fromArray()`

- [ ] **Step 3: 最小实现**

`src/Config.php` 在 `load()` 方法结束之后插入：

```php
    /** 用给定 env（AGENT_* 键值映射）构造实例；/config/test 与单测的临时配置入口 */
    public static function fromArray(array $env): self
    {
        return new self($env);
    }
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/ConfigTest.php`
Expected: PASS（全部用例）

- [ ] **Step 5: 提交**

```bash
git add src/Config.php tests/ConfigTest.php
git commit -m "feat: Config::fromArray 静态工厂（/config/test 与单测的临时配置入口）"
```

---

### Task 2: PHP `POST /config/test` 测试连接端点

**Files:**
- Modify: `src/Server/AgentServer.php`（路由表 + 新私有方法 + use 语句）
- Test: `tests/ServerApiTest.php`

**Interfaces:**
- Consumes: `Config::fromArray(array $env): self`（Task 1）；`OpenAICompatClient`/`AnthropicClient` 构造函数 `__construct(protected Config $config)`；`LLMException`
- Produces: `POST /config/test`，请求体 `{provider, api_key, base_url, model}`（snake_case，HTTP 接口惯例）；响应 `{ok: bool, message: string, latency_ms: int}`；provider 非法 → 400 `{error}`

- [ ] **Step 1: 写失败测试**

`tests/ServerApiTest.php` 类内追加（文件已有 `use React\Http\Message\ServerRequest;`，无需新增 use）：

```php
    public function testConfigTestFakeProviderReturnsOk(): void
    {
        $resp = $this->server()->handle(new ServerRequest('POST', 'http://localhost/config/test', [], json_encode([
            'provider' => 'fake', 'api_key' => '', 'base_url' => '', 'model' => '',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('latency_ms', $body);
        self::assertArrayHasKey('message', $body);
    }

    public function testConfigTestRejectsUnknownProvider(): void
    {
        $resp = $this->server()->handle(new ServerRequest('POST', 'http://localhost/config/test', [], json_encode([
            'provider' => 'nope',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(400, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertStringContainsStringIgnoringCase('provider', $body['error']);
    }

    public function testConfigTestOpenAiWithoutModelFailsFast(): void
    {
        $resp = $this->server()->handle(new ServerRequest('POST', 'http://localhost/config/test', [], json_encode([
            'provider' => 'openai', 'api_key' => 'sk-x',
        ], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), true);
        self::assertFalse($body['ok']);
        self::assertStringContainsString('模型', $body['message']);
    }
```

- [ ] **Step 2: 运行确认失败**

Run: `vendor/bin/phpunit tests/ServerApiTest.php`
Expected: FAIL（fake 用例得到 404 Not Found）

- [ ] **Step 3: 实现**

`src/Server/AgentServer.php`：

① 顶部 use 区追加：

```php
use PhpAgent\LLM\AnthropicClient;
use PhpAgent\LLM\LLMException;
use PhpAgent\LLM\OpenAICompatClient;
```

② 类 docblock 的路由清单里、`POST /agent` 一行后加：

```php
 *   POST /config/test      → 测试连接（桌面设置对话框；不落审计/日志）
```

③ `route()` 里 `if ($method === 'POST' && $path === '/agent')` 块之后插入：

```php
        if ($method === 'POST' && $path === '/config/test') {
            return $this->handleConfigTest($request);
        }
```

④ 类末尾新增私有方法（`handleAgent` 之后）：

```php
    /**
     * 测试连接：用表单当前值构造临时 Config，实例化对应 LLM client 发一次最小请求。
     * 消费到流自然结束（BaseSseClient 的消费契约；ping + AGENT_MAX_TOKENS=16 秒级完成）。
     * 响应不含 key，也不写任何日志/审计。
     */
    private function handleConfigTest(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $provider = strtolower(trim((string) ($body['provider'] ?? '')));
        if (!in_array($provider, ['openai', 'anthropic', 'fake'], true)) {
            return $this->json(400, ['error' => 'provider 必须是 openai|anthropic|fake']);
        }
        $tmp = Config::fromArray([
            'AGENT_PROVIDER' => $provider,
            'AGENT_API_KEY' => trim((string) ($body['api_key'] ?? '')),
            'AGENT_BASE_URL' => trim((string) ($body['base_url'] ?? '')),
            'AGENT_MODEL' => trim((string) ($body['model'] ?? '')),
            'AGENT_MAX_TOKENS' => '16',
        ]);
        if ($provider === 'fake') {
            return $this->json(200, ['ok' => true, 'message' => 'fake provider（离线）', 'latency_ms' => 0]);
        }
        if ($tmp->model() === '') {
            return $this->json(200, ['ok' => false, 'message' => '未配置模型', 'latency_ms' => 0]);
        }
        $client = $provider === 'anthropic' ? new AnthropicClient($tmp) : new OpenAICompatClient($tmp);
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'ping']]]];
        $start = (int) (microtime(true) * 1000);
        try {
            foreach ($client->stream($messages, [], '连接测试') as $_) {
                // 消费到自然结束；任一 HTTP/传输错误都会抛 LLMException
            }
        } catch (LLMException | \Throwable $e) {
            return $this->json(200, [
                'ok' => false,
                'message' => $e->getMessage(),
                'latency_ms' => (int) (microtime(true) * 1000) - $start,
            ]);
        }
        return $this->json(200, [
            'ok' => true,
            'message' => '连接成功',
            'latency_ms' => (int) (microtime(true) * 1000) - $start,
        ]);
    }
```

注意：catch 多类型捕获用 `LLMException | \Throwable` 是冗余的（Throwable 已涵盖），直接写 `catch (\Throwable $e)` 即可——但保留一行注释说明错误来自 LLMException。最终采用：

```php
        } catch (\Throwable $e) { // BaseSseClient 的 HTTP/传输错误统一抛 LLMException
```

- [ ] **Step 4: 运行确认通过**

Run: `vendor/bin/phpunit tests/ServerApiTest.php`
Expected: PASS（含既有用例）

- [ ] **Step 5: 全量回归**

Run: `vendor/bin/phpunit`
Expected: 全部 PASS

- [ ] **Step 6: 提交**

```bash
git add src/Server/AgentServer.php tests/ServerApiTest.php
git commit -m "feat: POST /config/test 测试连接端点（临时 Config + 现成 client 发 ping）"
```

---

### Task 3: Rust `SidecarConfig` + sidecar env 注入

**Files:**
- Modify: `desktop/src-tauri/src/sidecar.rs`
- Test: `desktop/src-tauri/src/sidecar.rs`（`#[cfg(test)] mod tests`）

**Interfaces:**
- Consumes: 现有 `Sidecar::start_with_env(php_bin, cwd, workspace, session_dir)`（本任务改签名）
- Produces: `SidecarConfig { provider, api_key, base_url, model }`（`serde::Serialize + Deserialize + Clone`，`rename_all = "camelCase"`，`SidecarConfig::empty()`）；`start_with_env(php_bin: &str, cwd: &Path, workspace: &str, session_dir: Option<&str>, config: Option<&SidecarConfig>)`。Task 4 的 lib.rs 依赖这两者

- [ ] **Step 1: 写失败测试（先适配既有测试签名）**

sidecar.rs 测试区：

① 两个既有集成测试的 `start_with_env(...)` 调用末尾追加参数 `None`（`sidecar_starts_and_stops` 与 `two_sidecars_run_concurrently` 各一处）。

② 追加新测试：

```rust
    /// 注入的 config env 必须覆盖 shell 层同名变量（cmd.env 直接写进程 environ）。
    #[test]
    fn sidecar_injects_config_env() {
        let root = std::path::PathBuf::from(env!("CARGO_MANIFEST_DIR"))
            .parent()
            .unwrap()
            .parent()
            .unwrap()
            .to_path_buf();
        std::env::set_var("AGENT_SESSION_STORE", "memory");
        std::env::set_var("AGENT_MODEL", "shell-model"); // shell 层同名值，应被注入覆盖
        let ws = std::env::temp_dir().join(format!("php-agent-cfg-{}", std::process::id()));
        std::fs::create_dir_all(&ws).unwrap();
        let cfg = SidecarConfig {
            provider: "fake".into(),
            api_key: String::new(),
            base_url: String::new(),
            model: "cfg-model".into(),
        };
        let mut sc = Sidecar::start_with_env("php", &root, &ws.to_string_lossy(), None, Some(&cfg))
            .expect("sidecar should start");
        use std::io::{Read, Write};
        let mut stream = TcpStream::connect(format!("127.0.0.1:{}", sc.port())).unwrap();
        stream
            .write_all(b"GET /status HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n")
            .unwrap();
        let _ = stream.set_read_timeout(Some(Duration::from_secs(5)));
        let mut buf = String::new();
        let _ = stream.read_to_string(&mut buf);
        assert!(buf.contains("\"model\":\"cfg-model\""), "注入的 model 应生效: {buf}");
        sc.stop();
        std::fs::remove_dir_all(&ws).ok();
    }
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop/src-tauri && cargo test`
Expected: 编译失败（`SidecarConfig` 未定义、`start_with_env` 参数不匹配）

- [ ] **Step 3: 实现**

sidecar.rs 顶部（`Sidecar` struct 之前）追加：

```rust
/// 桌面壳管理的全局 API 配置（存 ~/.php-agent/desktop.json 顶层 config 字段）。
/// camelCase 与前端 AgentConfig 字段对齐；启动 sidecar 时逐项注入 AGENT_*，空值不注入。
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "camelCase", default)]
pub struct SidecarConfig {
    pub provider: String,
    pub api_key: String,
    pub base_url: String,
    pub model: String,
}

impl SidecarConfig {
    pub fn empty() -> Self {
        Self {
            provider: String::new(),
            api_key: String::new(),
            base_url: String::new(),
            model: String::new(),
        }
    }
}
```

`start_with_env` 签名改为（并在函数内、`.stderr(Stdio::inherit())` 之前追加注入块）：

```rust
    pub fn start_with_env(
        php_bin: &str,
        cwd: &Path,
        workspace: &str,
        session_dir: Option<&str>,
        config: Option<&SidecarConfig>,
    ) -> Result<Sidecar, String> {
        let mut cmd = Command::new(php_bin);
        cmd.arg("bin/server")
            .current_dir(cwd)
            .env("AGENT_SERVER_PORT", "0")
            .env("AGENT_WORKSPACE", workspace)
            .env("AGENT_PERMISSIONS", "ask")
            .env("AGENT_UI", "server")
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit());

        // 全局配置注入（shell 权威：getenv 即最终值）；空字段不注入，保留 .env/系统 env 兜底
        if let Some(cfg) = config {
            if !cfg.provider.is_empty() {
                cmd.env("AGENT_PROVIDER", &cfg.provider);
            }
            if !cfg.api_key.is_empty() {
                cmd.env("AGENT_API_KEY", &cfg.api_key);
            }
            if !cfg.base_url.is_empty() {
                cmd.env("AGENT_BASE_URL", &cfg.base_url);
            }
            if !cfg.model.is_empty() {
                cmd.env("AGENT_MODEL", &cfg.model);
            }
        }

        if let Some(dir) = session_dir {
            cmd.env("AGENT_SESSION_DIR", dir);
        }
        // ……（函数其余部分不变）
```

（只展示到 session_dir 注入处，函数体其余照旧。）

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop/src-tauri && cargo test`
Expected: PASS（含 3 个 sidecar 集成测试；注意 sidecar 集成测试依赖本机 `php`）

- [ ] **Step 5: 提交**

```bash
git add desktop/src-tauri/src/sidecar.rs
git commit -m "feat: SidecarConfig + start_with_env 注入 AGENT_PROVIDER/API_KEY/BASE_URL/MODEL（空值不注入）"
```

---

### Task 4: Rust 持久化 config + `get_config`/`save_config` + `restart_session` 返回新会话

**Files:**
- Modify: `desktop/src-tauri/src/lib.rs`
- Test: `desktop/src-tauri/src/lib.rs`（新增 `#[cfg(test)] mod tests`）

**Interfaces:**
- Consumes: `SidecarConfig`（Task 3）、`start_with_env` 新签名、`SidecarConfig::empty()`
- Produces:
  - Tauri 命令 `get_config() -> SidecarConfig`、`save_config(provider, api_key, base_url, model) -> Result<(), String>`
  - `restart_session(app, session_id) -> Result<serde_json::Value, String>` 返回 `{sessionId, port, workspace}`（原来返回 `Result<(), String>`）
  - `PersistedState` 新增 `config: Option<SidecarConfig>`（serde default）

- [ ] **Step 1: 写失败测试**

lib.rs 文件末尾（`pub fn run()` 之后）追加：

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn persisted_state_parses_config_field() {
        let st: PersistedState = serde_json::from_str(
            r#"{"projects":[],"last_session":"","config":{"provider":"openai","apiKey":"sk-1","baseUrl":"https://x/v1","model":"glm-4.7"}}"#,
        )
        .unwrap();
        let cfg = st.config.expect("config 字段应被解析");
        assert_eq!(cfg.api_key, "sk-1");
        assert_eq!(cfg.model, "glm-4.7");
    }

    #[test]
    fn persisted_state_legacy_without_config_is_none() {
        let st: PersistedState =
            serde_json::from_str(r#"{"projects":["/a"],"last_session":"p-1"}"#).unwrap();
        assert!(st.config.is_none(), "旧格式无 config 字段 → None");
    }
}
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop/src-tauri && cargo test`
Expected: 编译失败（`config` 字段不存在）

- [ ] **Step 3: 实现**

① `PersistedState` 追加字段：

```rust
#[derive(serde::Deserialize, Default)]
struct PersistedState {
    #[serde(default)]
    projects: Vec<String>,
    #[serde(default)]
    last_session: String,
    #[serde(default)]
    last_workspace: Option<String>,
    // 兼容 v0.3.0 旧格式（单 workspace 字段迁移为 projects[0]）
    #[serde(default)]
    workspace: Option<String>,
    /// 全局 API 配置（v0.5.0+）；旧文件无此字段 → None
    #[serde(default)]
    config: Option<SidecarConfig>,
}
```

② `save_state` 重构为接收完整状态（替换现有 `fn save_state(projects: &[String], last_session: &str, last_workspace: &str)`）：

```rust
fn save_state_full(st: &PersistedState) {
    let path = desktop_json_path();
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let text = serde_json::json!({
        "projects": st.projects,
        "last_session": st.last_session,
        "last_workspace": st.last_workspace.as_ref().filter(|s| !s.is_empty()),
        "config": st.config,
    })
    .to_string();
    #[cfg(unix)]
    {
        use std::io::Write as _;
        if let Ok(mut f) = std::fs::File::create(&path) {
            let _ = f.write_all(text.as_bytes());
            // 含 api key：权限收紧到 0600
            use std::os::unix::fs::PermissionsExt;
            let _ = f.set_permissions(std::fs::Permissions::from_mode(0o600));
        }
    }
    #[cfg(not(unix))]
    let _ = std::fs::write(&path, text);
}
```

③ 四个调用点改为「load_state → 改字段 → save_state_full」模式：

`open_session_inner` 幂等路径（原 `save_state(&state.projects.lock().unwrap(), &last, workspace);`）：

```rust
                let mut st = load_state();
                st.last_session = id.clone();
                st.last_workspace = Some(workspace.to_string());
                save_state_full(&st);
```

`open_session_inner` spawn 成功路径——`Ok(sc) => {` 的整个块体替换为（内存态与持久化分开维护，与现状一致）：

```rust
        Ok(sc) => {
            let port = sc.port();
            state.sessions.lock().unwrap().insert(
                session_id.clone(),
                RunningSession { sidecar: sc, workspace: workspace.to_string() },
            );
            // 内存 projects 列表同步（原 position(...).unwrap_or_else(...) 块的等价改写）
            {
                let mut projects = state.projects.lock().unwrap();
                if !projects.iter().any(|p| p == &workspace) {
                    projects.push(workspace.to_string());
                }
            }
            *state.last_session.lock().unwrap() = session_id.clone();
            // 持久化（含 config 字段）
            let mut st = load_state();
            if !st.projects.iter().any(|p| p == &workspace) {
                st.projects.push(workspace.to_string());
            }
            st.last_session = session_id.clone();
            st.last_workspace = Some(workspace.to_string());
            save_state_full(&st);
            let _ = app.emit(
                "backend://ready",
                serde_json::json!({ "sessionId": session_id, "port": port, "workspace": workspace }),
            );
            Ok((session_id, port))
        }
```

`add_project`（原块尾 save_state）：

```rust
        let state = app.state::<AppState>();
        let mut projects = state.projects.lock().unwrap();
        if !projects.iter().any(|p| p == &path) {
            projects.push(path.clone());
        }
        let mut st = load_state();
        if !st.projects.iter().any(|p| p == &path) {
            st.projects.push(path.clone());
        }
        st.last_session = state.last_session.lock().unwrap().clone();
        st.last_workspace = Some(path.clone());
        save_state_full(&st);
```

`remove_project`（原 `let prev_ws = load_state().last_workspace.unwrap_or_default();` 行保留不动；`let new_ws = ...` 行删除；末尾 `save_state(&projects, &last, &new_ws);` 替换为）：

```rust
    let mut st = load_state();
    st.projects = projects.clone();
    st.last_session = state.last_session.lock().unwrap().clone();
    st.last_workspace = if prev_ws == path { None } else { Some(prev_ws) };
    save_state_full(&st);
    removed || projects.len() != before
```

（原返回值表达式 `removed || projects.len() != before` 并入上示代码块末尾。）

④ `open_session_inner` 读取配置并传给 sidecar（`Sidecar::start_with_env(...)` 调用处）：

```rust
    let global_cfg = load_state().config;
    let session_id = session_id_now();
    let (php_bin, backend_cwd) = resolve_backend(app);
    match Sidecar::start_with_env(
        &php_bin,
        &backend_cwd,
        workspace,
        Some(&session_dir.to_string_lossy()),
        global_cfg.as_ref(),
    ) {
```

⑤ `restart_session` 改为返回新会话：

```rust
#[tauri::command]
async fn restart_session(app: tauri::AppHandle, session_id: String) -> Result<serde_json::Value, String> {
    let workspace = {
        let state = app.state::<AppState>();
        let guard = state.sessions.lock().unwrap();
        guard
            .get(&session_id)
            .map(|r| r.workspace.clone())
            .ok_or_else(|| format!("会话 {session_id} 不存在"))?
    };
    close_session_inner(&app, &session_id);
    let a = app.clone();
    let (new_id, port) = tauri::async_runtime::spawn_blocking(move || open_session_inner(&a, &workspace))
        .await
        .map_err(|e| format!("重启 backend 失败: {e}"))??;
    Ok(serde_json::json!({ "sessionId": new_id, "port": port, "workspace": workspace }))
}
```

⑥ 新命令（`get_backend_url` 附近追加）：

```rust
#[tauri::command]
fn get_config() -> SidecarConfig {
    load_state().config.unwrap_or_else(SidecarConfig::empty)
}

#[tauri::command]
fn save_config(provider: String, api_key: String, base_url: String, model: String) -> Result<(), String> {
    let provider = provider.trim().to_lowercase();
    if !matches!(provider.as_str(), "openai" | "anthropic" | "fake") {
        return Err("provider 必须是 openai|anthropic|fake".into());
    }
    let mut st = load_state();
    st.config = Some(SidecarConfig { provider, api_key: api_key.trim().into(), base_url: base_url.trim().into(), model: model.trim().into() });
    save_state_full(&st);
    Ok(())
}
```

⑦ `invoke_handler` 注册表追加 `get_config, save_config,`（保留其余）。

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop/src-tauri && cargo test`
Expected: PASS（编译警告检查：无 unused）

- [ ] **Step 5: clippy 快检**

Run: `cd desktop/src-tauri && cargo clippy`
Expected: 无新增 error（warning 与存量持平即可）

- [ ] **Step 6: 提交**

```bash
git add desktop/src-tauri/src/lib.rs
git commit -m "feat: 桌面壳全局配置持久化（get_config/save_config）+ restart_session 返回新会话（修复重连后 tab 绑定失效）"
```

---

### Task 5: 前端 API 包装（tauri.ts / api.ts）+ UI store

**Files:**
- Modify: `desktop/src/lib/tauri.ts`
- Modify: `desktop/src/lib/api.ts`
- Create: `desktop/src/store/useUiStore.ts`

**Interfaces:**
- Consumes: Task 4 的 Tauri 命令与返回结构、Task 2 的 HTTP 端点
- Produces:
  - `type AgentConfig = { provider: string; apiKey: string; baseUrl: string; model: string }`
  - `getConfig(): Promise<AgentConfig>`、`saveConfig(cfg: AgentConfig): Promise<void>`
  - `restartSession(sessionId: string): Promise<OpenedSession>`（改返回类型）
  - `testConfig(baseUrl: string, cfg: AgentConfig): Promise<{ ok: boolean; message: string; latency_ms: number }>`
  - `useUiStore`：`{ settingsOpen: boolean; setSettingsOpen(v: boolean): void }`

- [ ] **Step 1: 修改 `desktop/src/lib/tauri.ts`**

`restartSession` 一行替换为：

```ts
export const restartSession = (sessionId: string) =>
  invoke<OpenedSession>("restart_session", { sessionId });
```

`getWorkspace` 之后追加：

```ts
/** 全局 API 配置（desktop.json 顶层 config 字段；camelCase 与后端序列化对齐） */
export type AgentConfig = { provider: string; apiKey: string; baseUrl: string; model: string };
export const getConfig = () => invoke<AgentConfig>("get_config");
export const saveConfig = (cfg: AgentConfig) =>
  invoke<void>("save_config", { provider: cfg.provider, apiKey: cfg.apiKey, baseUrl: cfg.baseUrl, model: cfg.model });
```

- [ ] **Step 2: 修改 `desktop/src/lib/api.ts`**

`getStatus` 之后追加：

```ts
export type ConfigTestResult = { ok: boolean; message: string; latency_ms: number };
/** POST /config/test：测试连接（桌面设置对话框用；走任意已连接 sidecar 的 baseUrl） */
export const testConfig = (baseUrl: string, cfg: { provider: string; apiKey: string; baseUrl: string; model: string }) =>
  json<ConfigTestResult>(baseUrl, "/config/test", {
    method: "POST",
    body: JSON.stringify({ provider: cfg.provider, api_key: cfg.apiKey, base_url: cfg.baseUrl, model: cfg.model }),
  });
```

- [ ] **Step 3: 创建 `desktop/src/store/useUiStore.ts`**

```ts
import { create } from "zustand";

/** 全局 UI 状态（与项目无关）：设置对话框开合。项目数据一律走 useProjectStore。 */
type UiState = {
  settingsOpen: boolean;
  setSettingsOpen: (v: boolean) => void;
};

export const useUiStore = create<UiState>((set) => ({
  settingsOpen: false,
  setSettingsOpen: (v) => set({ settingsOpen: v }),
}));
```

- [ ] **Step 4: 运行既有前端测试确认无回归**

Run: `cd desktop && npm test`
Expected: PASS（ConnectionBanner 等既有用例不受影响——restartSession 的 mock 返回值在 Task 7 才改）

- [ ] **Step 5: 提交**

```bash
git add desktop/src/lib/tauri.ts desktop/src/lib/api.ts desktop/src/store/useUiStore.ts
git commit -m "feat: 前端配置 API 包装（getConfig/saveConfig/testConfig）+ useUiStore"
```

---

### Task 6: `SettingsDialog` 组件

**Files:**
- Create: `desktop/src/components/settings/SettingsDialog.tsx`
- Test: `desktop/src/components/settings/SettingsDialog.test.tsx`

**Interfaces:**
- Consumes: `getConfig`/`saveConfig`（tauri.ts）、`testConfig`（api.ts）、`useUiStore`（Task 5）
- Produces: `<SettingsDialog testUrl: string | null, onSaved: (cfg: AgentConfig) => Promise<void> />` —— 对话框开合自持（useUiStore）；`testUrl` 为任意已连接 sidecar 的 baseUrl（null = 禁用测试按钮）；保存时回调 `onSaved`（App 实现 saveConfig+重启），失败信息显示在表单内

- [ ] **Step 1: 写失败测试**

```tsx
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { SettingsDialog } from "./SettingsDialog";
import { useUiStore } from "../../store/useUiStore";
import * as tauri from "../../lib/tauri";
import * as api from "../../lib/api";

vi.mock("../../lib/tauri", () => ({
  getConfig: vi.fn().mockResolvedValue({ provider: "openai", apiKey: "sk-old", baseUrl: "https://x/v1", model: "gpt-4o" }),
  saveConfig: vi.fn().mockResolvedValue(undefined),
}));
vi.mock("../../lib/api", () => ({ testConfig: vi.fn() }));

describe("SettingsDialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useUiStore.getState().setSettingsOpen(false);
  });

  it("open 时回填已保存配置", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={vi.fn()} />);
    await waitFor(() => expect(screen.getByDisplayValue("sk-old")).toBeInTheDocument());
    expect(screen.getByDisplayValue("gpt-4o")).toBeInTheDocument();
  });

  it("测试连接成功显示延迟", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: true, message: "连接成功", latency_ms: 320 });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={vi.fn()} />);
    fireEvent.click(await screen.findByText("测试连接"));
    await waitFor(() => expect(screen.getByText(/连接成功（320ms）/)).toBeInTheDocument());
  });

  it("测试连接失败显示错误信息", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: false, message: "401 未授权", latency_ms: 90 });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={vi.fn()} />);
    fireEvent.click(await screen.findByText("测试连接"));
    await waitFor(() => expect(screen.getByText(/401 未授权/)).toBeInTheDocument());
  });

  it("保存调用 onSaved 并带上表单值", async () => {
    const onSaved = vi.fn().mockResolvedValue(undefined);
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={onSaved} />);
    fireEvent.click(await screen.findByText("保存"));
    await waitFor(() =>
      expect(onSaved).toHaveBeenCalledWith({ provider: "openai", apiKey: "sk-old", baseUrl: "https://x/v1", model: "gpt-4o" }),
    );
  });

  it("testUrl 为 null 时测试按钮禁用", () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} onSaved={vi.fn()} />);
    expect(screen.getByText("测试连接")).toBeDisabled();
  });
});
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npx vitest run src/components/settings/SettingsDialog.test.tsx`
Expected: FAIL（模块不存在）

- [ ] **Step 3: 实现组件**

`desktop/src/components/settings/SettingsDialog.tsx`：

```tsx
import { useEffect, useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../ui/dialog";
import { Button } from "../ui/button";
import { Input } from "../ui/input";
import { getConfig, saveConfig, type AgentConfig } from "../../lib/tauri";
import { testConfig } from "../../lib/api";
import { useUiStore } from "../../store/useUiStore";

const PROVIDERS = [
  {
    id: "openai",
    label: "OpenAI 兼容（OpenAI / GLM / DeepSeek / Kimi…）",
    urlPlaceholder: "https://api.openai.com/v1",
    modelPlaceholder: "gpt-4o / glm-4.7 / deepseek-chat…",
  },
  {
    id: "anthropic",
    label: "Anthropic",
    urlPlaceholder: "https://api.anthropic.com",
    modelPlaceholder: "claude-sonnet-4-5",
  },
  {
    id: "fake",
    label: "fake（离线演示）",
    urlPlaceholder: "无需 Base URL",
    modelPlaceholder: "fake-model",
  },
];

export function SettingsDialog({
  testUrl,
  onSaved,
}: {
  testUrl: string | null;
  onSaved: (cfg: AgentConfig) => Promise<void>;
}) {
  const open = useUiStore((s) => s.settingsOpen);
  const setOpen = useUiStore((s) => s.setSettingsOpen);
  const [cfg, setCfg] = useState<AgentConfig>({ provider: "openai", apiKey: "", baseUrl: "", model: "" });
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);

  // 打开时回填持久化配置
  useEffect(() => {
    if (!open) return;
    setResult(null);
    getConfig().then(setCfg).catch(() => {});
  }, [open]);

  const meta = PROVIDERS.find((p) => p.id === cfg.provider) ?? PROVIDERS[0];

  async function runTest() {
    if (!testUrl) return;
    setTesting(true);
    setResult(null);
    try {
      const r = await testConfig(testUrl, cfg);
      setResult({ ok: r.ok, message: r.ok ? `连接成功（${r.latency_ms}ms）` : r.message });
    } catch (e: any) {
      setResult({ ok: false, message: e?.message ?? "测试请求失败" });
    } finally {
      setTesting(false);
    }
  }

  async function save() {
    setSaving(true);
    try {
      await saveConfig(cfg); // 持久化；重启各项目由 App 的 onSaved 编排
      await onSaved(cfg);
    } catch (e: any) {
      setResult({ ok: false, message: e?.message ?? "保存失败" });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>API 配置</DialogTitle>
          <DialogDescription>全局配置，对所有项目生效；保存后运行中的项目会自动重启。</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <label className="block space-y-1">
            <span className="text-sm text-muted">Provider</span>
            <select
              className="h-9 w-full rounded-md border border-border bg-surface px-2 text-sm text-ink"
              value={cfg.provider}
              onChange={(e) => setCfg({ ...cfg, provider: e.target.value })}
            >
              {PROVIDERS.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.label}
                </option>
              ))}
            </select>
          </label>
          <label className="block space-y-1">
            <span className="text-sm text-muted">Base URL（空 = 默认）</span>
            <Input
              value={cfg.baseUrl}
              placeholder={meta.urlPlaceholder}
              onChange={(e) => setCfg({ ...cfg, baseUrl: e.target.value })}
              className="font-mono"
            />
          </label>
          <label className="block space-y-1">
            <span className="text-sm text-muted">API Key</span>
            <Input
              type="password"
              value={cfg.apiKey}
              placeholder="sk-…"
              onChange={(e) => setCfg({ ...cfg, apiKey: e.target.value })}
              className="font-mono"
            />
          </label>
          <label className="block space-y-1">
            <span className="text-sm text-muted">Model</span>
            <Input
              value={cfg.model}
              placeholder={meta.modelPlaceholder}
              onChange={(e) => setCfg({ ...cfg, model: e.target.value })}
              className="font-mono"
            />
          </label>
          {result && (
            <p className={`text-xs ${result.ok ? "text-success" : "text-danger"}`}>
              {result.ok ? "✓ " : "✗ "}
              {result.message}
            </p>
          )}
        </div>
        <DialogFooter className="mt-2 gap-2">
          <Button variant="outline" size="sm" disabled={!testUrl || testing || saving} onClick={runTest}>
            {testing ? "测试中…" : "测试连接"}
          </Button>
          <Button size="sm" disabled={testing || saving} onClick={save}>
            {saving ? "保存中…" : "保存"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
```

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop && npx vitest run src/components/settings/SettingsDialog.test.tsx`
Expected: PASS（5 个用例）

- [ ] **Step 5: 提交**

```bash
git add desktop/src/components/settings/SettingsDialog.tsx desktop/src/components/settings/SettingsDialog.test.tsx
git commit -m "feat: SettingsDialog 设置对话框（provider/key/baseUrl/model + 测试连接 + 保存）"
```

---

### Task 7: App 集成 —— 保存后重启所有项目并重绑 tab + ConnectionBanner 修复

**Files:**
- Modify: `desktop/src/App.tsx`
- Modify: `desktop/src/components/ConnectionBanner.tsx`
- Test: `desktop/src/components/ConnectionBanner.test.tsx`

**Interfaces:**
- Consumes: `restartSession → OpenedSession`（Task 4/5）、`SettingsDialog`（Task 6）、`useUiStore`
- Produces:
  - App 渲染 `<SettingsDialog testUrl={activeTab 连接时其 backendUrl} onSaved={applyConfig} />`
  - `applyConfig(cfg)`：saveConfig 由对话框负责，此回调只做「重启各 tab → 重绑 id/端口 → 关闭对话框」
  - ConnectionBanner 重连后同样重绑 store 的 sessionId/backendUrl

- [ ] **Step 1: 更新 ConnectionBanner 测试（先失败）**

`desktop/src/components/ConnectionBanner.test.tsx`：`restartSession` 的 mock 改为返回新会话对象，并断言 store 重绑。整个文件替换为：

```tsx
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { ConnectionBanner } from "./ConnectionBanner";
import { useAppStore } from "../store/useAppStore";
import * as tauri from "../lib/tauri";

vi.mock("../lib/tauri", () => ({
  restartSession: vi.fn(),
  getWorkspace: vi.fn().mockResolvedValue("/tmp/project"),
}));

describe("ConnectionBanner", () => {
  beforeEach(() => {
    useAppStore.getState().reset();
    useAppStore.getState().setSessionId("p-1");
    useAppStore.getState().setBackend("http://127.0.0.1:1111");
    vi.clearAllMocks();
  });

  it("hidden when connected and no error", () => {
    useAppStore.getState().setConnected(true);
    const { container } = render(<ConnectionBanner onRetry={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows disconnect banner and reconnects", async () => {
    useAppStore.getState().setConnected(false);
    (tauri.restartSession as any).mockResolvedValueOnce({ sessionId: "p-2", port: 1235, workspace: "/tmp/project" });
    const onRetry = vi.fn();
    render(<ConnectionBanner onRetry={onRetry} />);
    expect(screen.getByText(/后端已断开/)).toBeInTheDocument();
    fireEvent.click(screen.getByText("重连"));
    await waitFor(() => expect(tauri.restartSession).toHaveBeenCalledWith("p-1"));
    // 重启产生新 sessionId/端口：store 必须重绑，否则后续请求打到旧地址
    await waitFor(() => {
      expect(useAppStore.getState().sessionId).toBe("p-2");
      expect(useAppStore.getState().backendUrl).toBe("http://127.0.0.1:1235");
    });
    expect(onRetry).toHaveBeenCalled();
  });

  it("surfaces reconnect failure in the store", async () => {
    useAppStore.getState().setConnected(false);
    (tauri.restartSession as any).mockRejectedValueOnce(new Error("spawn failed"));
    render(<ConnectionBanner onRetry={() => {}} />);
    fireEvent.click(screen.getByText("重连"));
    await waitFor(() => expect(useAppStore.getState().error).toBe("spawn failed"));
    // connected=false 时横幅仍显示断开文案（error 通过 StatusBar/后续 UI 呈现）
    expect(screen.getByText(/后端已断开/)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npx vitest run src/components/ConnectionBanner.test.tsx`
Expected: FAIL（重绑断言：sessionId 仍为 "p-1"）

- [ ] **Step 3: 修改 ConnectionBanner**

`reconnect` 函数改为：

```tsx
  async function reconnect() {
    store.getState().setError(null);
    try {
      // workspace 校验当前 sidecar 存活；restart 按同 workspace 重拉进程
      await getWorkspace(store.getState().sessionId);
      // restart 产生新 sessionId 与端口：立即重绑，否则后续请求打到旧地址
      const opened = await restartSession(store.getState().sessionId);
      store.getState().setSessionId(opened.sessionId);
      store.getState().setBackend(`http://127.0.0.1:${opened.port}`);
      onRetry();
    } catch (e: any) {
      store.getState().setError(e?.message ?? "重连失败");
    }
  }
```

- [ ] **Step 4: 运行确认通过**

Run: `cd desktop && npx vitest run src/components/ConnectionBanner.test.tsx`
Expected: PASS（3 个用例）

- [ ] **Step 5: App.tsx 集成**

① import 区追加：

```tsx
import { SettingsDialog } from "./components/settings/SettingsDialog";
import { saveConfig, type AgentConfig } from "./lib/tauri";
import { useUiStore } from "./store/useUiStore";
```

② `App()` 组件内、`addProject` 之后追加：

```tsx
  // 保存全局配置（持久化已由对话框完成）：重启所有运行中的项目并重绑新 sessionId/端口。
  // 重启失败的项目置 error + disconnected（ConnectionBanner 可重试），不影响其他项目。
  const applyConfig = useCallback(async (_cfg: AgentConfig) => {
    for (const tab of tabs) {
      try {
        const opened = await restartSession(tab.id);
        tab.store.getState().setBackend(`http://127.0.0.1:${opened.port}`);
        tab.store.getState().setSessionId(opened.sessionId);
        tab.store.getState().setError(null);
        // tab.id 变更会触发 ProjectWorkspace 的 refresh（重拉 status/sessions）
        setTabs((prev) => prev.map((t) => (t.id === tab.id ? { ...t, id: opened.sessionId } : t)));
      } catch (e: any) {
        tab.store.getState().setConnected(false);
        tab.store.getState().setError(e?.message ?? "重启失败");
      }
    }
    useUiStore.getState().setSettingsOpen(false);
  }, [tabs]);
```

（`restartSession` 需加入第 1 步的 tauri import 列表——检查现有 import，把 `restartSession` 加进 `from "./lib/tauri"` 的具名导入。）

③ 渲染：`EmptyProjects` 分支传 `onConfigure={() => useUiStore.getState().setSettingsOpen(true)}`；根 div 末尾（`</div>` 前）挂对话框：

```tsx
        {activeTab ? (
          <ProjectProvider store={activeTab.store}>
            <ProjectWorkspace tab={activeTab} onClose={() => { const t = activeTab; if (t) closeTab(t); }} />
          </ProjectProvider>
        ) : booted ? (
          <EmptyProjects
            projects={projects}
            booting={booting}
            onAdd={addProject}
            onOpen={(path) => openPath(path)}
            onConfigure={() => useUiStore.getState().setSettingsOpen(true)}
          />
        ) : (
          <div className="grid h-full place-items-center animate-pulse font-mono text-sm text-muted">正在启动…</div>
        )}
      </div>
      <SettingsDialog
        testUrl={activeTab?.store.getState().connected ? activeTab.store.getState().backendUrl : null}
        onSaved={applyConfig}
      />
    </div>
```

（`EmptyProjects` 的 `onConfigure` prop 在 Task 8 才加到组件签名——本任务先传 prop 会在 TS 报 unknown prop？React 对多余 prop 不报错，但组件不消费就没有入口。为保持每个任务可独立验证，本任务先只在根挂 `<SettingsDialog>`（TitleBar 徽章入口 Task 8 接上），`onConfigure` prop 随 Task 8 一起加。即本步骤 ③ 只加 `<SettingsDialog .../>` 这一段，EmptyProjects 调用不动。）

- [ ] **Step 6: 全量前端测试 + 类型检查**

Run: `cd desktop && npm test && npx tsc -b`
Expected: PASS、无类型错误

- [ ] **Step 7: 提交**

```bash
git add desktop/src/App.tsx desktop/src/components/ConnectionBanner.tsx desktop/src/components/ConnectionBanner.test.tsx
git commit -m "feat: App 集成设置对话框（保存后重启各项目重绑新会话）；ConnectionBanner 重连后重绑 sessionId/端口"
```

---

### Task 8: 配置入口 —— TitleBar 可点击徽章 + EmptyProjects「配置 API」按钮

**Files:**
- Modify: `desktop/src/components/layout/TitleBar.tsx`
- Modify: `desktop/src/components/layout/ProjectTabs.tsx`（仅 `EmptyProjects`）
- Modify: `desktop/src/App.tsx`（EmptyProjects 传 `onConfigure`）
- Test: `desktop/src/components/layout/TitleBar.test.tsx`（新建）

**Interfaces:**
- Consumes: `useUiStore`（Task 5）；App 已渲染的 `<SettingsDialog>`（Task 7）
- Produces: 点击徽章/按钮 → `setSettingsOpen(true)` → 打开对话框

- [ ] **Step 1: 写失败测试**

`desktop/src/components/layout/TitleBar.test.tsx`：

```tsx
import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { TitleBar } from "./TitleBar";
import { useAppStore } from "../../store/useAppStore";
import { useUiStore } from "../../store/useUiStore";

describe("TitleBar 配置入口", () => {
  beforeEach(() => {
    useAppStore.getState().reset();
    useUiStore.getState().setSettingsOpen(false);
  });

  it("model 未配置时徽章显示 未配置 且为警示色", () => {
    useAppStore.getState().setStatus({
      provider: "openai", model: "", workspace: "/tmp", permissions: "ask",
      sessionStore: "json", mcp: true, version: "0.2.0", maxIterations: 25,
    });
    render(<TitleBar />);
    const badge = screen.getByText(/openai\/未配置/);
    expect(badge).toBeInTheDocument();
    expect(badge.className).toContain("text-warning");
  });

  it("点击徽章打开设置对话框", () => {
    useAppStore.getState().setStatus({
      provider: "openai", model: "gpt-4o", workspace: "/tmp", permissions: "ask",
      sessionStore: "json", mcp: true, version: "0.2.0", maxIterations: 25,
    });
    render(<TitleBar />);
    fireEvent.click(screen.getByText(/openai\/gpt-4o/));
    expect(useUiStore.getState().settingsOpen).toBe(true);
  });
});
```

- [ ] **Step 2: 运行确认失败**

Run: `cd desktop && npx vitest run src/components/layout/TitleBar.test.tsx`
Expected: FAIL（徽章不可点击；未配置无警示色）

- [ ] **Step 3: 修改 TitleBar**

provider 徽章（原 `{status && (<Badge ...>...)}` 第一处）替换为：

```tsx
        {status && (
          <button
            type="button"
            onClick={() => useUiStore.getState().setSettingsOpen(true)}
            title="配置 API"
            className="cursor-pointer"
          >
            <Badge
              variant="outline"
              className={`gap-1 border-border font-mono text-[11px] ${status.model ? "text-muted" : "text-warning"}`}
            >
              <Cpu size={12} /> {status.provider}/{status.model || "未配置"}
            </Badge>
          </button>
        )}
```

import 区追加：

```tsx
import { Cpu, FolderGit2, Settings, ShieldCheck, ShieldOff } from "lucide-react";
import { useUiStore } from "../../store/useUiStore";
```

（`Settings` 图标本任务未用到则不 import——只加 `useUiStore`。）

- [ ] **Step 4: EmptyProjects 加配置按钮**

`ProjectTabs.tsx` 的 `EmptyProjects`：签名追加 `onConfigure: () => void`；「添加项目目录」按钮之后追加：

```tsx
        <button
          className="flex items-center gap-2 rounded-lg border border-border bg-surface px-5 py-2.5 text-sm text-muted transition-colors hover:border-primary hover:text-ink"
          onClick={onConfigure}
        >
          <Settings size={16} /> 配置 API
        </button>
```

import 区确认有 `Settings`（lucide-react）。

`App.tsx` 的 `EmptyProjects` 调用补上 prop：

```tsx
            onConfigure={() => useUiStore.getState().setSettingsOpen(true)}
```

- [ ] **Step 5: 运行确认通过**

Run: `cd desktop && npm test && npx tsc -b`
Expected: PASS（全部用例）、无类型错误

- [ ] **Step 6: 提交**

```bash
git add desktop/src/components/layout/TitleBar.tsx desktop/src/components/layout/TitleBar.test.tsx desktop/src/components/layout/ProjectTabs.tsx desktop/src/App.tsx
git commit -m "feat: 标题栏徽章与空项目页接入配置入口（未配置橙色警示，点击打开设置）"
```

---

## 验收（手工，全部任务完成后）

1. `cd desktop && npm run tauri dev` 启动
2. 全新状态（删除 `~/.php-agent/desktop.json` 可复现）：标题栏显示橙色 `openai/未配置` → 点击 → 填 GLM/OpenAI 兼容端点 + key + model → 「测试连接」显示 `✓ 连接成功（…ms）` → 保存 → 徽章变 `openai/<model>` 白色
3. 发消息得到真实回复；再次修改 provider → 保存 → 两个已打开项目 tab 均自动重启且会话历史可恢复
4. 断开 sidecar（kill 进程）→ 重连 → 聊天仍可用（验证重绑修复）
5. `cat ~/.php-agent/desktop.json`：`config` 字段存在；`ls -l` 权限 `-rw-------`；`/status` 响应无 key
