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

pub struct Sidecar {
    child: Child,
    port: u16,
}

impl Sidecar {
    /// spawn `php bin/server`，注入 env，阻塞等待端口行 + 探活
    pub fn start(workspace: &str, project_root: &Path) -> Result<Sidecar, String> {
        let mut cmd = Command::new("php");
        cmd.arg("bin/server")
            .current_dir(project_root)
            .env("AGENT_SERVER_PORT", "0")
            .env("AGENT_WORKSPACE", workspace)
            .env("AGENT_PERMISSIONS", "ask")
            .env("AGENT_UI", "server")
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit());

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
            for line in reader.lines().map_while(Result::ok) {
                if let Some(port) = parse_port_line(&line) {
                    let _ = tx.send(port);
                    break;
                }
            }
        });

        let port = rx
            .recv_timeout(Duration::from_secs(15))
            .map_err(|_| "sidecar 未在 15s 内报告端口".to_string())?;

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
}