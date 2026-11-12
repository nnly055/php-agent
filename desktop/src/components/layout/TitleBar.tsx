import { useProjectStore } from "../../store/useProjectStore";
import { Badge } from "../ui/badge";
import { Cpu, FolderGit2, ShieldCheck, ShieldOff } from "lucide-react";
import { useUiStore } from "../../store/useUiStore";

export function TitleBar() {
  const store = useProjectStore();
  const status = store((s) => s.status);
  const connected = store((s) => s.connected);
  return (
    <header className="flex h-12 shrink-0 items-center gap-3 border-b border-border bg-surface px-4">
      <div className="flex items-center gap-2">
        <span className="grid h-6 w-6 place-items-center rounded-md bg-primary-deep font-mono text-xs font-bold text-white">php</span>
        <span className="text-sm font-semibold tracking-tight text-ink">PHP Agent</span>
      </div>
      <span className="flex items-center gap-1 text-xs text-muted">
        <FolderGit2 size={13} /> {status?.workspace ?? "…"}
      </span>
      <div className="ml-auto flex items-center gap-2">
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
        {status && (
          <Badge variant="outline" className="gap-1 border-border text-[11px] text-muted">
            {status.permissions === "ask" ? <ShieldCheck size={12} className="text-success" /> : <ShieldOff size={12} className="text-warning" />}
            {status.permissions === "ask" ? "按需授权" : "全部放行"}
          </Badge>
        )}
        <span className={`h-2 w-2 rounded-full ${connected ? "bg-success" : "bg-danger"}`} title={connected ? "已连接" : "未连接"} />
      </div>
    </header>
  );
}
