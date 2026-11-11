pub mod sidecar;

use sidecar::Sidecar;
use std::path::PathBuf;
use std::sync::Mutex;
use tauri::{Emitter, Manager, State};

struct AppState {
    sidecar: Mutex<Option<Sidecar>>,
    workspace: Mutex<String>,
    project_root: PathBuf,
}

fn desktop_json_path() -> PathBuf {
    let home = std::env::var("HOME").unwrap_or_else(|_| ".".into());
    PathBuf::from(home).join(".php-agent").join("desktop.json")
}

fn load_workspace(default: &str) -> String {
    let path = desktop_json_path();
    if let Ok(text) = std::fs::read_to_string(&path) {
        if let Ok(v) = serde_json::from_str::<serde_json::Value>(&text) {
            if let Some(ws) = v.get("workspace").and_then(|w| w.as_str()) {
                if std::path::Path::new(ws).is_dir() {
                    return ws.to_string();
                }
            }
        }
    }
    default.to_string()
}

fn save_workspace(ws: &str) {
    let path = desktop_json_path();
    if let Some(parent) = path.parent() {
        let _ = std::fs::create_dir_all(parent);
    }
    let _ = std::fs::write(&path, serde_json::json!({ "workspace": ws }).to_string());
}

/// 停旧 sidecar 并按当前 workspace 拉起新的；成功 emit `backend://ready`，失败 emit `backend://error`。
///
/// 只接收 `AppHandle`（而非 `&AppState`），以便整体移入 `spawn_blocking` 的 'static 闭包，
/// 避免阻塞 async runtime / 主线程。state 通过 `Manager::state` 在函数内解析。
fn start_sidecar(app: &tauri::AppHandle) {
    let state = app.state::<AppState>();
    let ws = state.workspace.lock().unwrap().clone();
    // 停旧
    {
        let mut guard = state.sidecar.lock().unwrap();
        if let Some(mut old) = guard.take() {
            old.stop();
            let _ = app.emit("backend://disconnected", serde_json::json!({}));
        }
    }
    match Sidecar::start(&ws, &state.project_root) {
        Ok(sc) => {
            let port = sc.port();
            *state.sidecar.lock().unwrap() = Some(sc);
            let _ = app.emit("backend://ready", serde_json::json!({ "port": port }));
        }
        Err(e) => {
            let _ = app.emit("backend://error", serde_json::json!({ "message": e }));
        }
    }
}

#[tauri::command]
fn get_backend_url(state: State<AppState>) -> Result<String, String> {
    let guard = state.sidecar.lock().unwrap();
    match guard.as_ref() {
        Some(sc) => Ok(format!("http://127.0.0.1:{}", sc.port())),
        None => Err("backend 未就绪".into()),
    }
}

#[tauri::command]
fn get_workspace(state: State<AppState>) -> String {
    state.workspace.lock().unwrap().clone()
}

#[tauri::command]
async fn pick_workspace(
    app: tauri::AppHandle,
    state: State<'_, AppState>,
) -> Result<Option<String>, String> {
    use tauri_plugin_dialog::DialogExt;
    // blocking_pick_folder 会阻塞当前线程；放到阻塞线程池执行，避免卡住 async runtime。
    let dialog_app = app.clone();
    let picked = tauri::async_runtime::spawn_blocking(move || {
        dialog_app.dialog().file().blocking_pick_folder()
    })
    .await
    .map_err(|e| format!("无法打开目录选择对话框: {e}"))?;

    match picked {
        Some(path) => {
            // FilePath 可能是 Path 或 Url；into_path 统一转成 PathBuf（桌面端目录选择恒为 Path）。
            let ws = path
                .into_path()
                .map_err(|e| format!("所选路径无效: {e}"))?
                .to_string_lossy()
                .to_string();
            if !std::path::Path::new(&ws).is_dir() {
                return Err("所选目录不存在".into());
            }
            *state.workspace.lock().unwrap() = ws.clone();
            save_workspace(&ws);
            // 重启 sidecar 同样走阻塞线程池，避免阻塞 async runtime。
            let restart_app = app.clone();
            tauri::async_runtime::spawn_blocking(move || start_sidecar(&restart_app))
                .await
                .map_err(|e| format!("重启 backend 失败: {e}"))?;
            Ok(Some(ws))
        }
        None => Ok(None),
    }
}

#[tauri::command]
async fn restart_backend(app: tauri::AppHandle) -> Result<(), String> {
    tauri::async_runtime::spawn_blocking(move || start_sidecar(&app))
        .await
        .map_err(|e| format!("重启 backend 失败: {e}"))?;
    Ok(())
}

pub fn run() {
    // 项目根 = CARGO_MANIFEST_DIR(= desktop/src-tauri) 上两级，dev/build 都稳定。
    let project_root = PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .parent()
        .and_then(|p| p.parent())
        .map(|p| p.to_path_buf())
        .unwrap_or_else(|| PathBuf::from("."));

    let default_ws = project_root.to_string_lossy().to_string();
    let workspace = load_workspace(&default_ws);

    let app = tauri::Builder::default()
        .plugin(tauri_plugin_dialog::init())
        .plugin(tauri_plugin_single_instance::init(|app, _args, _cwd| {
            if let Some(w) = app.get_webview_window("main") {
                let _ = w.set_focus();
            }
        }))
        .manage(AppState {
            sidecar: Mutex::new(None),
            workspace: Mutex::new(workspace),
            project_root,
        })
        .invoke_handler(tauri::generate_handler![
            get_backend_url,
            get_workspace,
            pick_workspace,
            restart_backend
        ])
        .setup(|app| {
            // SIGINT/SIGTERM（含终端 Ctrl+C）→ 干净退出，杜绝孤儿 php sidecar。
            // "termination" feature 同时覆盖 SIGTERM；handler 跑在独立线程，
            // AppHandle::exit 经事件循环代理发消息，线程安全。exit(0) 会触发
            // RunEvent::ExitRequested，在下方 run 回调里统一停 sidecar。
            let signal_handle = app.handle().clone();
            if let Err(e) = ctrlc::set_handler(move || signal_handle.exit(0)) {
                eprintln!("无法安装 SIGINT/SIGTERM 处理器: {e}");
            }
            let handle = app.handle().clone();
            start_sidecar(&handle);
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("error while building tauri application");

    app.run(|app, event| {
        // 窗口关闭与 exit(0)（信号）都路由到这里：整组 kill + wait，防孤儿 PHP。
        if let tauri::RunEvent::ExitRequested { .. } = event {
            if let Some(mut sc) = app.state::<AppState>().sidecar.lock().unwrap().take() {
                sc.stop();
            }
        }
    });
}