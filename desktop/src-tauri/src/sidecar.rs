use std::io::{BufRead, BufReader};
use std::net::TcpStream;
use std::path::Path;
use std::process::{Child, Command, Stdio};
use std::sync::mpsc;
use std::time::Duration;

/// 从 stdout 行解析 `AGENT_LISTENING port=<port>`（纯函数，可单测）
pub fn parse_port_line(line: &str) -> Option<u16> {
    let line = line.trim();
    let rest = line.strip_prefix("AGENT_LISTENING port=")?;
    rest.trim().parse::<u16>().ok()
}

/// 桌面壳管理的全局 API 配置（存 ~/.php-agent/desktop.json 顶层 config 字段）。
/// camelCase 与前端 AgentConfig 字段对齐；启动 sidecar 时逐项注入 AGENT_*，空值不注入。
#[derive(Debug, Clone, Default, serde::Serialize, serde::Deserialize)]
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

pub struct Sidecar {
    child: Child,
    port: u16,
}

/// 项目路径 → 稳定的目录 slug（会话存储按项目隔离：~/.php-agent/projects/<slug>/sessions）。
/// 清洗末段目录名 + path 的 crc32（手写实现，跨版本稳定，不依赖 DefaultHasher）。
pub fn project_slug(path: &str) -> String {
    let last = Path::new(path)
        .file_name()
        .map(|s| s.to_string_lossy().to_string())
        .unwrap_or_else(|| path.to_string());
    let mut cleaned = String::new();
    let mut prev_dash = false;
    for c in last.chars() {
        if c.is_ascii_alphanumeric() {
            cleaned.push(c.to_ascii_lowercase());
            prev_dash = false;
        } else if !prev_dash && !cleaned.is_empty() {
            cleaned.push('-');
            prev_dash = true;
        }
    }
    while cleaned.ends_with('-') {
        cleaned.pop();
    }
    let base = if cleaned.is_empty() { "project".to_string() } else { cleaned };
    format!("{}-{:08x}", base, crc32(path.as_bytes()))
}

/// 纯 crc32（IEEE 802.3 多项式），不引入依赖。
pub fn crc32(data: &[u8]) -> u32 {
    let mut crc: u32 = 0xFFFF_FFFF;
    for &b in data {
        crc ^= b as u32;
        for _ in 0..8 {
            let mask = (crc & 1).wrapping_neg();
            crc = (crc >> 1) ^ (0xEDB8_8320 & mask);
        }
    }
    !crc
}

impl Sidecar {
    /// spawn `php bin/server`，阻塞等待端口行 + 探活。
    /// `php_bin` 为 PHP 解释器（bundled 静态二进制或系统 php）；
    /// `cwd` 为内核代码根（bundled 资源目录或项目根），`bin/server` 的
    /// `$root = dirname(__DIR__)` 由此确定，plugins/public/vendor 全在其中。
    /// `session_dir` 为空则不注入 AGENT_SESSION_DIR（PHP 侧用默认 ~/.php-agent/sessions）。
    /// `config` 为全局 API 配置（Some 时逐项注入 AGENT_PROVIDER 等，空字段跳过，保留 .env 兜底）。
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

        #[cfg(unix)]
        {
            use std::os::unix::process::CommandExt;
            cmd.process_group(0); // 独立进程组，便于整组清理
        }

        let mut child = cmd.spawn().map_err(|e| format!("无法启动 php sidecar: {e}"))?;
        let stdout = child.stdout.take().ok_or("无法读取 sidecar stdout")?;
        let (tx, rx) = mpsc::channel::<u16>();
        std::thread::spawn(move || {
            let reader = BufReader::new(stdout);
            let mut sent = false;
            for line in reader.lines().map_while(Result::ok) {
                if !sent {
                    if let Some(port) = parse_port_line(&line) {
                        let _ = tx.send(port);
                        sent = true;
                    }
                }
                // 端口行之后继续 drain（丢弃），保持管道开放，php 后续 stdout 写入不会 EPIPE
            }
        });

        let port = match rx.recv_timeout(Duration::from_secs(15)) {
            Ok(p) => p,
            Err(_) => {
                let _ = kill_group(&mut child);
                let _ = child.wait();
                return Err("sidecar 未在 15s 内报告端口".to_string());
            }
        };

        // 探活：端口可连接即就绪（/status 由前端拉取）
        let addr = format!("127.0.0.1:{port}");
        let mut ready = false;
        for _ in 0..50 {
            if TcpStream::connect(&addr).is_ok() {
                ready = true;
                break;
            }
            std::thread::sleep(Duration::from_millis(100));
        }
        if !ready {
            let _ = kill_group(&mut child);
            let _ = child.wait(); // reap，与超时路径一致，避免僵尸
            return Err("sidecar 端口无法连接".into());
        }
        Ok(Sidecar { child, port })
    }

    pub fn port(&self) -> u16 {
        self.port
    }

    pub fn is_alive(&mut self) -> bool {
        matches!(self.child.try_wait(), Ok(None))
    }

    pub fn stop(&mut self) {
        let _ = kill_group(&mut self.child);
        let _ = self.child.wait();
    }
}

impl Drop for Sidecar {
    fn drop(&mut self) {
        self.stop();
    }
}

#[cfg(unix)]
fn kill_group(child: &mut Child) -> std::io::Result<()> {
    let pid = child.id() as i32;
    // 杀整个进程组（php 及其 MCP stdio 子进程）
    let ret = unsafe { libc::killpg(pid, libc::SIGKILL) };
    if ret != 0 {
        // 回退：单进程 kill
        child.kill()?;
    }
    Ok(())
}

#[cfg(not(unix))]
fn kill_group(child: &mut Child) -> std::io::Result<()> {
    child.kill()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_port_line() {
        assert_eq!(parse_port_line("AGENT_LISTENING port=54321"), Some(54321));
        assert_eq!(parse_port_line("AGENT_LISTENING port=0"), Some(0));
        assert_eq!(parse_port_line("  AGENT_LISTENING port=8091  "), Some(8091));
        assert_eq!(parse_port_line("PHP Agent 本地服务已启动"), None);
        assert_eq!(parse_port_line("AGENT_LISTENING port=abc"), None);
        assert_eq!(parse_port_line("AGENT_LISTENING port=99999"), None);
    }

    #[test]
    fn slug_is_stable_and_sanitized() {
        let a = project_slug("/Users/x/my-app");
        let b = project_slug("/Users/x/my-app");
        assert_eq!(a, b, "同一路径 slug 稳定");
        assert!(a.starts_with("my-app-"), "slug 以清洗后的目录名开头: {a}");
        assert!(
            a.chars().all(|c| c.is_ascii_alphanumeric() || c == '-'),
            "slug 只含 [a-z0-9-]: {a}"
        );
        assert_ne!(project_slug("/Users/x/other"), a, "不同路径 slug 不同");
        // 非 ASCII 目录名整段被清洗，但 crc32 仍区分
        assert_ne!(project_slug("/Users/x/项目甲"), project_slug("/Users/x/项目乙"));
    }

    #[test]
    fn crc32_matches_known_vectors() {
        assert_eq!(crc32(b""), 0);
        assert_eq!(crc32(b"123456789"), 0xCBF4_3926, "IEEE 802.3 标准校验向量");
    }

    /// 集成测试：真实 spawn php sidecar，验证生命周期 + 无孤儿。
    /// start 后 is_alive 为 true 证明 php 在 banner/日志写入后仍存活（stdout 管道保持开放）。
    #[test]
    fn sidecar_starts_and_stops() {
        // 项目根 = src-tauri 上两级
        let root = std::path::PathBuf::from(env!("CARGO_MANIFEST_DIR"))
            .parent()
            .unwrap()
            .parent()
            .unwrap()
            .to_path_buf();
        // fake provider 离线启动（shell env 经 shellWins 生效）
        std::env::set_var("AGENT_PROVIDER", "fake");
        std::env::set_var("AGENT_SESSION_STORE", "memory");
        let ws = std::env::temp_dir().join(format!("php-agent-sc-test-{}", std::process::id()));
        std::fs::create_dir_all(&ws).unwrap();
        let mut sc = Sidecar::start_with_env("php", &root, &ws.to_string_lossy(), None, None)
            .expect("sidecar should start");
        assert!(sc.port() > 0, "port should be assigned");
        assert!(
            sc.is_alive(),
            "sidecar should be alive after start (pipe stays open)"
        );
        sc.stop();
        assert!(!sc.is_alive(), "sidecar should be dead after stop");
        std::fs::remove_dir_all(&ws).ok();
    }

    /// 多项目并发：两个 sidecar（不同 workspace/session dir）同时存活、端口互不相同。
    #[test]
    fn two_sidecars_run_concurrently() {
        let root = std::path::PathBuf::from(env!("CARGO_MANIFEST_DIR"))
            .parent()
            .unwrap()
            .parent()
            .unwrap()
            .to_path_buf();
        std::env::set_var("AGENT_PROVIDER", "fake");
        std::env::set_var("AGENT_SESSION_STORE", "memory");

        let ws1 = std::env::temp_dir().join(format!("php-agent-mc-a-{}", std::process::id()));
        let ws2 = std::env::temp_dir().join(format!("php-agent-mc-b-{}", std::process::id()));
        let sd1 = ws1.join("sessions");
        let sd2 = ws2.join("sessions");
        std::fs::create_dir_all(&sd1).unwrap();
        std::fs::create_dir_all(&sd2).unwrap();

        let mut a = Sidecar::start_with_env(
            "php",
            &root,
            &ws1.to_string_lossy(),
            Some(&sd1.to_string_lossy()),
            None,
        )
        .expect("sidecar A should start");
        let mut b = Sidecar::start_with_env(
            "php",
            &root,
            &ws2.to_string_lossy(),
            Some(&sd2.to_string_lossy()),
            None,
        )
        .expect("sidecar B should start");

        assert!(a.is_alive() && b.is_alive());
        assert_ne!(a.port(), b.port(), "OS 分配的端口不应相同");

        // 各自探活自己的 /status
        for port in [a.port(), b.port()] {
            use std::io::{Read, Write};
            let mut stream = TcpStream::connect(format!("127.0.0.1:{port}")).unwrap();
            stream
                .write_all(format!("GET /status HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\nConnection: close\r\n\r\n").as_bytes())
                .unwrap();
            let mut buf = String::new();
            let _ = stream.set_read_timeout(Some(Duration::from_secs(5)));
            let _ = stream.read_to_string(&mut buf);
            assert!(buf.contains("200"), "sidecar on {port} should answer /status");
        }

        a.stop();
        b.stop();
        assert!(!a.is_alive() && !b.is_alive());
        std::fs::remove_dir_all(&ws1).ok();
        std::fs::remove_dir_all(&ws2).ok();
    }

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
}