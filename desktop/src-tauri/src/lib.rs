pub mod sidecar;

use sidecar::{project_slug, Sidecar, SidecarConfig};
use std::collections::{BTreeMap, HashMap};
use std::path::PathBuf;
use std::sync::Mutex;
use tauri::{Emitter, Manager, State};

/// 一个已打开的项目会话 = 一个独立 PHP sidecar 进程（独立端口/工作区/会话目录）。
struct RunningSession {
    sidecar: Sidecar,
    workspace: String,
}

struct AppState {
    /// sessionId → 运行中的 sidecar
    sessions: Mutex<HashMap<String, RunningSession>>,
    /// 项目列表（持久化，顺序即标签页顺序）
    projects: Mutex<Vec<String>>,
    /// 最近激活的 sessionId（启动时前端据此懒恢复）
    last_session: Mutex<String>,
    project_root: PathBuf,
}

/// 用户主目录：macOS/Linux 用 HOME，Windows 用 USERPROFILE
fn home_dir() -> String {
    std::env::var("HOME")
        .or_else(|_| std::env::var("USERPROFILE"))
        .unwrap_or_else(|_| ".".into())
}

fn desktop_json_path() -> PathBuf {
    PathBuf::from(home_dir()).join(".php-agent").join("desktop.json")
}

/// 全局 MCP 配置（所有项目共享）；格式与项目 .mcp.json 一致，PHP McpConfig::globalPath() 对齐
fn global_mcp_path() -> PathBuf {
    PathBuf::from(home_dir()).join(".php-agent").join("mcp.json")
}

/// 一个 MCP server 条目：name 必填（文件中以 map 键承载，写盘时剔除）；
/// stdio = command(+args/env)，http = url(+headers)；timeout 秒，None = PHP 侧默认 30。
/// 空字段不落盘（skip_serializing_if），与 Claude Code / Cursor 的 .mcp.json 形态一致。
#[derive(Debug, Clone, PartialEq, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct McpServerEntry {
    #[serde(default)]
    pub name: String,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub command: String,
    #[serde(default, skip_serializing_if = "Vec::is_empty")]
    pub args: Vec<String>,
    #[serde(default, skip_serializing_if = "BTreeMap::is_empty")]
    pub env: BTreeMap<String, String>,
    #[serde(default, skip_serializing_if = "String::is_empty")]
    pub url: String,
    #[serde(default, skip_serializing_if = "BTreeMap::is_empty")]
    pub headers: BTreeMap<String, String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub timeout: Option<f64>,
}

/// 一个模型条目：name 为对话/请求所用模型名；contextTokens/maxTokens 为可选 per-model 参数
#[derive(Debug, Clone, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ModelEntry {
    pub name: String,
    #[serde(default)]
    pub context_tokens: Option<u64>,
    #[serde(default)]
    pub max_tokens: Option<u64>,
}

/// models 双格式反序列化：数组项为纯字符串（旧 desktop.json / 旧前端）或对象（新格式）均可；
/// 序列化恒为对象（Vec<ModelEntry> 的普通 derive 即可）。
fn deserialize_models<'de, D>(deserializer: D) -> Result<Vec<ModelEntry>, D::Error>
where
    D: serde::Deserializer<'de>,
{
    #[derive(serde::Deserialize)]
    #[serde(untagged)]
    enum RawEntry {
        Str(String),
        Obj(ModelEntry),
    }
    let raw = <Vec<RawEntry> as serde::Deserialize>::deserialize(deserializer)?;
    Ok(raw
        .into_iter()
        .map(|e| match e {
            RawEntry::Str(s) => ModelEntry { name: s, context_tokens: None, max_tokens: None },
            RawEntry::Obj(m) => m,
        })
        .collect())
}

/// 一个平台档：name 供 UI 显示；models 为该平台可选模型列表（首个为默认）
// Default 手写与 derive 等价（空串/空 vec），derive 以满足 clippy::derivable_impls
#[derive(Debug, Clone, Default, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "camelCase", default)]
pub struct ProfileConfig {
    pub id: String,
    pub name: String,
    pub provider: String,
    pub api_key: String,
    pub base_url: String,
    #[serde(default, deserialize_with = "deserialize_models")]
    pub models: Vec<ModelEntry>,
}

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
    /// 旧单档全局配置：仅保留解析能力以供 migrate_legacy 迁移，不再写盘
    #[serde(default)]
    config: Option<SidecarConfig>,
    /// 平台档目录（schema v2，v0.6.0+）；旧文件无此字段 → 空
    #[serde(default)]
    profiles: Vec<ProfileConfig>,
    // 顶层键沿用 camelCase（与 save_state_full 写盘键一致；旧顶层键其余仍为 snake_case）
    #[serde(default, rename = "defaultProfile")]
    default_profile: Option<String>,
    #[serde(default, rename = "defaultModel")]
    default_model: Option<String>,
}

/// 旧单档 config → profiles[default]（无损迁移；无 config 则保持空）
fn migrate_legacy(mut st: PersistedState) -> PersistedState {
    if st.profiles.is_empty() {
        if let Some(cfg) = st.config.take() {
            if !cfg.provider.is_empty() {
                let mut models = Vec::new();
                if !cfg.model.is_empty() {
                    models.push(ModelEntry { name: cfg.model.clone(), context_tokens: None, max_tokens: None });
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

fn load_state() -> PersistedState {
    let mut st = match std::fs::read_to_string(desktop_json_path()) {
        Ok(text) => serde_json::from_str::<PersistedState>(&text).unwrap_or_default(),
        Err(_) => PersistedState::default(),
    };
    // 旧格式迁移：workspace → projects
    if st.projects.is_empty() {
        if let Some(ws) = st.workspace.take() {
            if std::path::Path::new(&ws).is_dir() {
                st.projects.push(ws);
            }
        }
    }
    st.projects.retain(|p| std::path::Path::new(p).is_dir());
    // 旧单档 config → profiles 迁移（幂等：已有 profiles 则原样返回）
    migrate_legacy(st)
}

fn save_state_full(st: &PersistedState) {
    let path = desktop_json_path();
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let text = serde_json::json!({
        "projects": st.projects,
        "last_session": st.last_session,
        "last_workspace": st.last_workspace.as_ref().filter(|s| !s.is_empty()),
        "profiles": st.profiles,
        "defaultProfile": st.default_profile,
        "defaultModel": st.default_model,
    })
    .to_string();
    #[cfg(unix)]
    {
        use std::io::Write as _;
        use std::os::unix::fs::OpenOptionsExt;
        // mode(0o600)：新建即权限正确（先 create 后 chmod 存在 key 以 0644 可见的窗口）
        if let Ok(mut f) = std::fs::OpenOptions::new()
            .write(true)
            .create(true)
            .truncate(true)
            .mode(0o600)
            .open(&path)
        {
            let _ = f.write_all(text.as_bytes());
            // 旧文件（可能 0644）补一次收紧；失败仅在 stderr 提示，不静默
            use std::os::unix::fs::PermissionsExt;
            if f.metadata().is_ok_and(|m| m.permissions().mode() & 0o777 != 0o600)
                && f.set_permissions(std::fs::Permissions::from_mode(0o600)).is_err()
            {
                eprintln!("无法收紧 {} 权限到 0600", path.display());
            }
        }
    }
    #[cfg(not(unix))]
    let _ = std::fs::write(&path, text);
}

/// 每项目独立的会话存储目录：~/.php-agent/projects/<slug>/sessions
fn session_dir_for(workspace: &str) -> PathBuf {
    PathBuf::from(home_dir())
        .join(".php-agent")
        .join("projects")
        .join(project_slug(workspace))
        .join("sessions")
}

/// 解析 PHP 解释器与后端 cwd（三级回退，返回 (php 命令, bin/server 的工作目录)）：
///   1. env PHP_AGENT_PHP（开发期覆盖）
///   2. bundle 资源里的静态 php（resources/php-agent/php，打包分发零系统依赖）
///   3. 系统 "php"（dev 兜底）
/// bundled 模式下 cwd = 资源目录 php-agent/，bin/server 的 $root 指向它，
/// 插件发现与 public/ 资源结构在打包后保持不变。
fn resolve_backend(app: &tauri::AppHandle) -> (String, PathBuf) {
    if let Ok(custom) = std::env::var("PHP_AGENT_PHP") {
        if !custom.is_empty() {
            let root = app.state::<AppState>().project_root.clone();
            return (custom, root);
        }
    }
    if let Ok(res) = app.path().resource_dir() {
        let bundled = res.join("php-agent");
        // Windows 静态产物为 php.exe，其余平台为 php
        let php = if cfg!(windows) { bundled.join("php.exe") } else { bundled.join("php") };
        if php.is_file() {
            // resource 拷贝可能丢失可执行位，启动前确保
            #[cfg(unix)]
            {
                use std::os::unix::fs::PermissionsExt;
                let _ = std::fs::set_permissions(&php, std::fs::Permissions::from_mode(0o755));
            }
            // macOS：静态 php 是 linker-signed，陈旧签名可能被安全策略（syspolicyd）
            // 卡死 exec——进程在内核不可杀地挂起、无任何输出（2026-11-12 事故，重签即解锁）。
            // 每次解析后重签 ad-hoc 触发全新鉴定；codesign 是系统平台二进制，自身不受影响。
            #[cfg(target_os = "macos")]
            {
                let _ = std::process::Command::new("codesign")
                    .args(["--force", "--sign", "-"])
                    .arg(&php)
                    .stdout(std::process::Stdio::null())
                    .stderr(std::process::Stdio::null())
                    .status();
            }
            return (php.to_string_lossy().to_string(), bundled);
        }
    }
    let root = app.state::<AppState>().project_root.clone();
    ("php".to_string(), root)
}

fn session_id_now() -> String {
    let nanos = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_nanos())
        .unwrap_or(0);
    format!("p-{:x}", nanos)
}

/// spawn 一个项目会话（幂等：同一路径已有存活实例则直接复用）。
/// 成功 emit `backend://ready {sessionId, port, workspace}`；失败 emit `backend://error {sessionId?, message}`。
/// 阻塞操作（spawn + 15s 探活）由调用方放入 spawn_blocking。
fn open_session_inner(
    app: &tauri::AppHandle,
    workspace: &str,
) -> Result<(String, u16), String> {
    let state = app.state::<AppState>();
    let session_dir = session_dir_for(workspace);
    let _ = std::fs::create_dir_all(&session_dir);

    // 幂等：已有该工作区的存活会话直接复用
    {
        let guard = state.sessions.lock().unwrap();
        for (id, running) in guard.iter() {
            if running.workspace == workspace {
                let port = running.sidecar.port();
                let mut last = state.last_session.lock().unwrap();
                *last = id.clone();
                let mut st = load_state();
                st.last_session = id.clone();
                st.last_workspace = Some(workspace.to_string());
                save_state_full(&st);
                let _ = app.emit(
                    "backend://ready",
                    serde_json::json!({ "sessionId": id, "port": port, "workspace": workspace }),
                );
                return Ok((id.clone(), port));
            }
        }
    }

    let global_cfg = default_sidecar_config(&load_state());
    let session_id = session_id_now();
    let (php_bin, backend_cwd) = resolve_backend(app);
    match Sidecar::start_with_env(
        &php_bin,
        &backend_cwd,
        workspace,
        Some(&session_dir.to_string_lossy()),
        global_cfg.as_ref(),
    ) {
        Ok(sc) => {
            let port = sc.port();
            state.sessions.lock().unwrap().insert(
                session_id.clone(),
                RunningSession { sidecar: sc, workspace: workspace.to_string() },
            );
            // 内存 projects 列表同步（原 position(...).unwrap_or_else(...) 块的等价改写）
            {
                let mut projects = state.projects.lock().unwrap();
                if !projects.iter().any(|p| p == workspace) {
                    projects.push(workspace.to_string());
                }
            }
            *state.last_session.lock().unwrap() = session_id.clone();
            // 持久化（含 profiles 目录）
            let mut st = load_state();
            if !st.projects.iter().any(|p| p == workspace) {
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
        Err(e) => {
            // 失败必须可见：spawn 错误此前只进事件通道（tab 未建时前端无处显示），终端一片安静
            eprintln!("[php-agent] 启动 sidecar 失败 (workspace={workspace}): {e}");
            let _ = app.emit(
                "backend://error",
                serde_json::json!({ "sessionId": session_id, "message": e }),
            );
            Err(e)
        }
    }
}

fn close_session_inner(app: &tauri::AppHandle, session_id: &str) -> bool {
    let state = app.state::<AppState>();
    let removed = state.sessions.lock().unwrap().remove(session_id);
    if let Some(mut running) = removed {
        running.sidecar.stop();
        let _ = app.emit(
            "backend://disconnected",
            serde_json::json!({ "sessionId": session_id }),
        );
        true
    } else {
        false
    }
}

#[tauri::command]
fn list_projects() -> Vec<String> {
    // 只读持久化列表（不含运行态）；运行态由前端经 sessions_ready 维护
    load_state().projects
}

#[tauri::command]
fn get_last_session() -> serde_json::Value {
    let st = load_state();
    serde_json::json!({ "sessionId": st.last_session, "workspace": st.last_workspace })
}

#[tauri::command]
async fn open_project(app: tauri::AppHandle, path: String) -> Result<serde_json::Value, String> {
    if !std::path::Path::new(&path).is_dir() {
        return Err("所选目录不存在".into());
    }
    let a = app.clone();
    let p = path.clone();
    let (session_id, port) = tauri::async_runtime::spawn_blocking(move || open_session_inner(&a, &p))
        .await
        .map_err(|e| format!("启动 backend 失败: {e}"))??;
    Ok(serde_json::json!({ "sessionId": session_id, "port": port, "workspace": path }))
}

#[tauri::command]
async fn add_project(app: tauri::AppHandle) -> Result<Option<serde_json::Value>, String> {
    use tauri_plugin_dialog::DialogExt;
    let dialog_app = app.clone();
    let picked = tauri::async_runtime::spawn_blocking(move || {
        dialog_app.dialog().file().blocking_pick_folder()
    })
    .await
    .map_err(|e| format!("无法打开目录选择对话框: {e}"))?;

    let path = match picked {
        Some(p) => p
            .into_path()
            .map_err(|e| format!("所选路径无效: {e}"))?
            .to_string_lossy()
            .to_string(),
        None => return Ok(None),
    };
    if !std::path::Path::new(&path).is_dir() {
        return Err("所选目录不存在".into());
    }

    // 加入持久化列表（已存在则不动）。State 引用不得跨越 await（Future 需 Send），
    // 全部访问收进块作用域。
    {
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
    }

    let a = app.clone();
    let path_for_spawn = path.clone();
    let (session_id, port) = tauri::async_runtime::spawn_blocking(move || open_session_inner(&a, &path_for_spawn))
        .await
        .map_err(|e| format!("启动 backend 失败: {e}"))??;
    Ok(Some(
        serde_json::json!({ "sessionId": session_id, "port": port, "workspace": path }),
    ))
}

#[tauri::command]
fn close_project(app: tauri::AppHandle, session_id: String) -> bool {
    close_session_inner(&app, &session_id)
}

#[tauri::command]
fn remove_project(app: tauri::AppHandle, path: String) -> bool {
    let state = app.state::<AppState>();
    // 关掉该项目所有运行中的会话
    let ids: Vec<String> = state
        .sessions
        .lock()
        .unwrap()
        .iter()
        .filter(|(_, r)| r.workspace == path)
        .map(|(id, _)| id.clone())
        .collect();
    drop(state);
    let mut removed = false;
    for id in ids {
        removed |= close_session_inner(&app, &id);
    }
    let state = app.state::<AppState>();
    let mut projects = state.projects.lock().unwrap();
    let before = projects.len();
    projects.retain(|p| p != &path);
    // 被移除的项目若是最近激活项，清空恢复指针；否则保留
    let prev_ws = load_state().last_workspace.unwrap_or_default();
    let mut st = load_state();
    st.projects = projects.clone();
    st.last_session = state.last_session.lock().unwrap().clone();
    st.last_workspace = if prev_ws == path { None } else { Some(prev_ws) };
    save_state_full(&st);
    removed || projects.len() != before
}

#[tauri::command]
fn get_backend_url(state: State<AppState>, session_id: String) -> Result<String, String> {
    let guard = state.sessions.lock().unwrap();
    match guard.get(&session_id) {
        Some(running) => Ok(format!("http://127.0.0.1:{}", running.sidecar.port())),
        None => Err(format!("会话 {session_id} 的 backend 未就绪")),
    }
}

/// profiles 目录校验：非空、id 唯一非空、provider 合法、默认档必须在目录中。
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
        if p.models.iter().any(|m| m.name.trim().is_empty()) {
            return Err(format!("模型 name 不能为空: {}", p.id));
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
    let model = st.default_model.clone().or_else(|| p.models.first().map(|m| m.name.clone())).unwrap_or_default();
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

// ── MCP 配置（设置对话框「MCP」Tab；读写文件，重启项目后由 McpPlugin 生效）──

/// 写盘前校验（镜像 PHP 语义）：名称非空唯一、command/url 二选一、timeout ≥ 1
fn validate_mcp(entries: &[McpServerEntry]) -> Result<(), String> {
    for e in entries {
        if e.name.trim().is_empty() {
            return Err("MCP server 名称不能为空".into());
        }
        let has_command = !e.command.trim().is_empty();
        let has_url = !e.url.trim().is_empty();
        if has_command == has_url {
            return Err(format!("MCP server '{}'：command 与 url 必须二选一", e.name));
        }
        if let Some(t) = e.timeout {
            if t < 1.0 {
                return Err(format!("MCP server '{}'：timeout 须为 ≥ 1 的秒数", e.name));
            }
        }
    }
    for i in 0..entries.len() {
        for j in (i + 1)..entries.len() {
            if entries[i].name == entries[j].name {
                return Err(format!("MCP server 名称重复: {}", entries[i].name));
            }
        }
    }
    Ok(())
}

/// 读一个 mcp.json：缺失 = 静默空；损坏 = Err（消息含路径）；条目非法 = Err（消息含 server 名）
fn parse_mcp_file(path: &std::path::Path) -> Result<Vec<McpServerEntry>, String> {
    let text = match std::fs::read_to_string(path) {
        Ok(t) => t,
        Err(_) => return Ok(vec![]), // 缺失静默（含不可读），与 PHP「文件缺失 = no-op」对齐
    };
    let v: serde_json::Value = serde_json::from_str(&text)
        .map_err(|_| format!("MCP 配置 {} 不是合法 JSON", path.display()))?;
    let servers = v.get("mcpServers").and_then(|m| m.as_object()).ok_or_else(|| {
        format!("MCP 配置 {} 缺少 mcpServers 对象", path.display())
    })?;
    let mut entries = Vec::new();
    for (name, item) in servers {
        let mut e: McpServerEntry = serde_json::from_value(item.clone())
            .map_err(|err| format!("MCP server '{}' 配置无效: {err}", name))?;
        e.name = name.clone(); // name 以 map 键为准（文件条目不含 name 键）
        entries.push(e);
    }
    Ok(entries)
}

/// 写一个 mcp.json：校验后逐条序列化并剔除 name 键（文件条目以 map 键承载名称）
fn write_mcp_file(path: &std::path::Path, entries: &[McpServerEntry]) -> Result<(), String> {
    validate_mcp(entries)?;
    let mut map = serde_json::Map::new();
    for e in entries {
        let mut v = serde_json::to_value(e).map_err(|err| err.to_string())?;
        if let Some(obj) = v.as_object_mut() {
            obj.remove("name");
        }
        map.insert(e.name.clone(), v);
    }
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent).map_err(|err| err.to_string())?;
    }
    let text = serde_json::json!({ "mcpServers": serde_json::Value::Object(map) }).to_string();
    std::fs::write(path, text).map_err(|err| err.to_string())
}

#[tauri::command]
fn get_mcp(workspace: Option<String>) -> Result<serde_json::Value, String> {
    let global = parse_mcp_file(&global_mcp_path())?;
    let project = match workspace {
        Some(ws) if !ws.is_empty() => {
            let path = PathBuf::from(ws).join(".mcp.json");
            Some(parse_mcp_file(&path)?)
        }
        _ => None,
    };
    Ok(serde_json::json!({ "global": global, "project": project }))
}

#[tauri::command]
fn save_mcp_global(servers: Vec<McpServerEntry>) -> Result<(), String> {
    write_mcp_file(&global_mcp_path(), &servers)
}

#[tauri::command]
fn save_mcp_project(workspace: String, servers: Vec<McpServerEntry>) -> Result<(), String> {
    write_mcp_file(&PathBuf::from(workspace).join(".mcp.json"), &servers)
}

#[tauri::command]
fn get_workspace(state: State<AppState>, session_id: String) -> Result<String, String> {
    let guard = state.sessions.lock().unwrap();
    guard
        .get(&session_id)
        .map(|r| r.workspace.clone())
        .ok_or_else(|| format!("会话 {session_id} 不存在"))
}

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
    // workspace 需在闭包外用于返回值，先克隆再移交（同 open_project 模式）
    let ws = workspace.clone();
    let (new_id, port) = tauri::async_runtime::spawn_blocking(move || open_session_inner(&a, &ws))
        .await
        .map_err(|e| format!("重启 backend 失败: {e}"))??;
    Ok(serde_json::json!({ "sessionId": new_id, "port": port, "workspace": workspace }))
}

pub fn run() {
    // 项目根 = CARGO_MANIFEST_DIR(= desktop/src-tauri) 上两级，dev/build 都稳定。
    let project_root = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .parent()
        .and_then(|p| p.parent())
        .map(|p| p.to_path_buf())
        .unwrap_or_else(|| PathBuf::from("."));

    let persisted = load_state();

    let app = tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            if let Some(w) = app.get_webview_window("main") {
                let _ = w.set_focus();
            }
        }))
        .manage(AppState {
            sessions: Mutex::new(HashMap::new()),
            projects: Mutex::new(persisted.projects.clone()),
            last_session: Mutex::new(persisted.last_session.clone()),
            project_root,
        })
        .invoke_handler(tauri::generate_handler![
            list_projects,
            get_last_session,
            open_project,
            add_project,
            close_project,
            remove_project,
            get_backend_url,
            get_workspace,
            restart_session,
            get_profiles,
            save_profiles,
            get_mcp,
            save_mcp_global,
            save_mcp_project
        ])
        .setup(|_app| {
            // SIGINT/SIGTERM（含终端 Ctrl+C）→ 干净退出，杜绝孤儿 php sidecar。
            let signal_handle = _app.handle().clone();
            if let Err(e) = ctrlc::set_handler(move || signal_handle.exit(0)) {
                eprintln!("无法安装 SIGINT/SIGTERM 处理器: {e}");
            }
            // 项目 sidecar 改为前端懒启动（open_project）：避免启动时全量拉起 N 个进程
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("error while building tauri application");

    app.run(|app, event| {
        // 窗口关闭与 exit(0)（信号）都路由到这里：全部整组 kill + wait，防孤儿 PHP。
        if let tauri::RunEvent::ExitRequested { .. } = event {
            let state = app.state::<AppState>();
            let mut guard = state.sessions.lock().unwrap();
            for (_, mut running) in guard.drain() {
                running.sidecar.stop();
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    /// 测试辅助：仅 name 的模型条目（per-model 参数为 None）
    fn me(name: &str) -> ModelEntry {
        ModelEntry { name: name.into(), context_tokens: None, max_tokens: None }
    }

    fn sample_profiles() -> Vec<ProfileConfig> {
        vec![ProfileConfig {
            id: "p-1".into(), name: "DS".into(), provider: "openai".into(),
            api_key: "sk-1".into(), base_url: "https://api.deepseek.com".into(),
            models: vec![me("deepseek-chat")],
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
        // 旧字符串项 → ModelEntry（name 保留，参数为 None）
        assert_eq!(st.profiles[0].models[0].name, "m1");
        assert_eq!(st.profiles[0].models[0].context_tokens, None);
        assert_eq!(st.profiles[0].models[0].max_tokens, None);
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
        assert_eq!(migrated.profiles[0].models.len(), 1);
        assert_eq!(migrated.profiles[0].models[0].name, "m-old");
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

    #[test]
    fn models_legacy_string_item_parses() {
        // 旧格式：数组项为纯字符串 → name 保留、per-model 参数为 None
        let st: PersistedState = serde_json::from_str(
            r#"{"profiles":[{"id":"p-1","name":"DS","provider":"openai","apiKey":"k","baseUrl":"https://x","models":["m1"]}],"defaultProfile":"p-1"}"#,
        ).unwrap();
        assert_eq!(st.profiles[0].models.len(), 1);
        assert_eq!(st.profiles[0].models[0].name, "m1");
        assert_eq!(st.profiles[0].models[0].context_tokens, None);
        assert_eq!(st.profiles[0].models[0].max_tokens, None);
    }

    #[test]
    fn models_object_item_parses() {
        // 新格式：数组项为对象（camelCase 参数可选）
        let st: PersistedState = serde_json::from_str(
            r#"{"profiles":[{"id":"p-1","name":"DS","provider":"openai","apiKey":"k","baseUrl":"https://x","models":[{"name":"m1","contextTokens":128000,"maxTokens":8192}]}],"defaultProfile":"p-1"}"#,
        ).unwrap();
        let m = &st.profiles[0].models[0];
        assert_eq!(m.name, "m1");
        assert_eq!(m.context_tokens, Some(128000));
        assert_eq!(m.max_tokens, Some(8192));
    }

    #[test]
    fn models_mixed_array_parses() {
        // 混合数组：字符串项与对象项并存
        let st: PersistedState = serde_json::from_str(
            r#"{"profiles":[{"id":"p-1","name":"DS","provider":"openai","apiKey":"k","baseUrl":"https://x","models":["legacy",{"name":"m2","contextTokens":32000},{"name":"m3"}]}],"defaultProfile":"p-1"}"#,
        ).unwrap();
        let models = &st.profiles[0].models;
        assert_eq!(models.len(), 3);
        assert_eq!(models[0].name, "legacy");
        assert_eq!(models[0].context_tokens, None);
        assert_eq!(models[1].name, "m2");
        assert_eq!(models[1].context_tokens, Some(32000));
        assert_eq!(models[1].max_tokens, None);
        assert_eq!(models[2].name, "m3");
    }

    #[test]
    fn models_serialize_always_objects() {
        // 序列化恒为对象：即使 models 由字符串解析而来
        let st: PersistedState = serde_json::from_str(
            r#"{"profiles":[{"id":"p-1","name":"DS","provider":"openai","apiKey":"k","baseUrl":"https://x","models":["m1"]}],"defaultProfile":"p-1"}"#,
        ).unwrap();
        let text = serde_json::to_string(&st.profiles[0]).unwrap();
        assert!(text.contains(r#""models":[{"name":"m1""#), "序列化应为对象形态: {text}");
    }

    #[test]
    fn models_roundtrip_lossless() {
        // 往返无损：字符串解析 → 序列化（对象）→ 再解析，name/参数不变
        let st: PersistedState = serde_json::from_str(
            r#"{"profiles":[{"id":"p-1","name":"DS","provider":"openai","apiKey":"k","baseUrl":"https://x","models":["m1",{"name":"m2","contextTokens":128000,"maxTokens":8192}]}],"defaultProfile":"p-1"}"#,
        ).unwrap();
        let text = serde_json::to_string(&st.profiles[0]).unwrap();
        let back: ProfileConfig = serde_json::from_str(&text).unwrap();
        assert_eq!(back.models.len(), 2);
        assert_eq!(back.models[0].name, "m1");
        assert_eq!(back.models[0].context_tokens, None);
        assert_eq!(back.models[1].name, "m2");
        assert_eq!(back.models[1].context_tokens, Some(128000));
        assert_eq!(back.models[1].max_tokens, Some(8192));
    }

    #[test]
    fn validate_profiles_rejects_empty_model_name() {
        // 条目 name 为空（含纯空白）拒绝
        let mut bad = sample_profiles();
        bad[0].models[0].name = "  ".into();
        assert!(validate_profiles(&bad, "p-1").is_err(), "模型 name 空白拒绝");
        let mut empty = sample_profiles();
        empty[0].models[0].name = "".into();
        assert!(validate_profiles(&empty, "p-1").is_err(), "模型 name 空串拒绝");
    }

    // ── MCP 配置（get_mcp/save_mcp_*）──

    /// 测试辅助：仅 name+command 的 MCP server 条目
    fn mcp_entry(name: &str, command: &str) -> McpServerEntry {
        McpServerEntry {
            name: name.into(),
            command: command.into(),
            args: vec![],
            env: Default::default(),
            url: String::new(),
            headers: Default::default(),
            timeout: None,
        }
    }

    #[test]
    fn validate_mcp_rejects_bad_entries() {
        assert!(validate_mcp(&[mcp_entry("a", "php")]).is_ok());
        assert!(validate_mcp(&[mcp_entry("", "php")]).is_err(), "名称空");
        assert!(
            validate_mcp(&[mcp_entry("a", "php"), mcp_entry("a", "php")]).is_err(),
            "名称重复"
        );
        let both = McpServerEntry { url: "https://a".into(), ..mcp_entry("b", "php") };
        assert!(validate_mcp(&[both]).is_err(), "command/url 二选一");
        assert!(validate_mcp(&[mcp_entry("b", "")]).is_err(), "两者皆空");
        let bad_timeout = McpServerEntry { timeout: Some(0.5), ..mcp_entry("c", "php") };
        assert!(validate_mcp(&[bad_timeout]).is_err(), "timeout < 1");
    }

    #[test]
    fn mcp_file_round_trip_and_php_compat() {
        let dir = std::env::temp_dir().join(format!("php-agent-mcp-rs-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("mcp.json");
        let entries = vec![McpServerEntry {
            args: vec!["-y".into()],
            env: [("K".to_string(), "v".to_string())].into_iter().collect(),
            timeout: Some(5.0),
            ..mcp_entry("fs", "npx")
        }];
        write_mcp_file(&path, &entries).unwrap();
        let text = std::fs::read_to_string(&path).unwrap();
        let v: serde_json::Value = serde_json::from_str(&text).unwrap();
        let item = &v["mcpServers"]["fs"];
        assert_eq!(item["command"], "npx");
        assert_eq!(item["args"][0], "-y");
        assert_eq!(item["env"]["K"], "v");
        assert_eq!(item["timeout"], 5.0);
        assert!(item.get("name").is_none(), "文件条目不含 name 键");
        assert!(item.get("url").is_none(), "空 url 不落盘");
        // PHP 兼容格式回读：name 从 map 键注入
        let parsed = parse_mcp_file(&path).unwrap();
        assert_eq!(parsed[0].name, "fs");
        assert_eq!(parsed[0].args, vec!["-y".to_string()]);
        std::fs::remove_file(&path).unwrap();
        std::fs::remove_dir(&dir).unwrap();
    }

    #[test]
    fn parse_mcp_file_errors() {
        let dir = std::env::temp_dir().join(format!("php-agent-mcp-rs-e-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("mcp.json");
        assert_eq!(parse_mcp_file(&path).unwrap(), vec![], "缺失静默空");
        std::fs::write(&path, "{ nope").unwrap();
        assert!(parse_mcp_file(&path).is_err(), "损坏报错");
        std::fs::remove_file(&path).unwrap();
        std::fs::remove_dir(&dir).unwrap();
    }
}
