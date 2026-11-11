import { useProjectStore } from "../../store/useProjectStore";

export function StatusBar() {
  const store = useProjectStore();
  const { status, activeSession, streaming, connected } = store();
  return (
    <footer className="flex h-7 shrink-0 items-center gap-4 border-t border-border bg-surface px-4 font-mono text-[11px] text-muted">
      <span className={connected ? "text-success" : "text-danger"}>{connected ? "● 已连接" : "○ 未连接"}</span>
      <span>会话 {activeSession || "—"}</span>
      {streaming && <span className="text-info">流式输出中…</span>}
      <span className="ml-auto">v{status?.version ?? "0.3.0"} · {status?.sessionStore ?? ""}</span>
    </footer>
  );
}
