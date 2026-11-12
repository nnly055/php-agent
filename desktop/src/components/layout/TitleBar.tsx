import { useProjectStore } from "../../store/useProjectStore";
import { Badge } from "../ui/badge";
import { Cpu, FolderGit2, ShieldCheck, ShieldOff } from "lucide-react";
import { useUiStore } from "../../store/useUiStore";

export function TitleBar() {
  const store = useProjectStore();
  const status = store((s) => s.status);
  const selection = store((s) => s.selection);
  const connected = store((s) => s.connected);
  // 全局凭据目录：徽章优先显示当前会话选择解析出的档名与模型，回落到 /status
  const profilesPayload = useUiStore((s) => s.profilesPayload);
  const selProfile = profilesPayload?.profiles.find((p) => p.id === (selection.profileId ?? profilesPayload?.defaultProfile));
  // defaultModel 不参与回落：服务端从不使用，展示 profile 首个模型才与实际请求一致
  const selModel = selection.model ?? selProfile?.models[0] ?? "";
  const badgeText = selProfile
    ? `${selProfile.name || selProfile.provider}▸${selModel || "未选"}`
    : `${status?.provider ?? "?"}/${status?.model || "未配置"}`;
  // 仅在目录为空且后端也未配置模型时才警示（有目录即视为可用，哪怕尚未就绪）
  const unconfigured = !selProfile && !status?.model && !(profilesPayload && profilesPayload.profiles.length > 0);
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
        <button
          type="button"
          onClick={() => useUiStore.getState().setSettingsOpen(true)}
          title="配置 API"
          className="cursor-pointer"
        >
          <Badge
            variant="outline"
            className={`gap-1 border-border font-mono text-[11px] ${unconfigured ? "text-warning" : "text-muted"}`}
          >
            <Cpu size={12} /> {badgeText}
          </Badge>
        </button>
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
