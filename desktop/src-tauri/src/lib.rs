pub mod sidecar;

use sidecar::{project_slug, Sidecar, SidecarConfig};
use std::collections::HashMap;
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
    st
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
        "config": st.config,
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
            // 持久化（含 config 字段）
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

#[tauri::command]
fn get_config() -> SidecarConfig {
    normalize_config(load_state().config.unwrap_or_else(SidecarConfig::empty))
}

/// 空 provider 回落 openai（与 PHP Config 默认一致）。
/// 设置对话框的 select 只认合法 provider，空值会让保存必然失败。
fn normalize_config(mut cfg: SidecarConfig) -> SidecarConfig {
    if cfg.provider.is_empty() {
        cfg.provider = "openai".into();
    }
    cfg
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
            get_config,
            save_config
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

    #[test]
    fn normalize_config_fills_empty_provider() {
        let cfg = normalize_config(SidecarConfig::empty());
        assert_eq!(cfg.provider, "openai", "空 provider 回落 openai");
        assert_eq!(cfg.model, "", "其余字段保持为空");

        let mut custom = SidecarConfig::empty();
        custom.provider = "anthropic".into();
        assert_eq!(normalize_config(custom).provider, "anthropic", "合法值透传");
    }

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
