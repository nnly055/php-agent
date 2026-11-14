import { useProjectStore } from "../../store/useProjectStore";
import { Badge } from "../ui/badge";
import { Cpu, FolderOpen } from "lucide-react";
import { useUiStore } from "../../store/useUiStore";

/** 会话标题栏：📁 会话标题 + 模型徽章 + 连接点。整条为窗口拖拽区（macOS Overlay 模式）。 */
export function SessionHeader() {
  const store = useProjectStore();
  const status = store((s) => s.status);
  const activeSession = store((s) => s.activeSession);
  const sessions = store((s) => s.sessions);
  const connected = store((s) => s.connected);
  const selection = store((s) => s.selection);
  const title = sessions.find((s) => s.id === activeSession)?.title ?? "新会话";
  // 全局凭据目录：徽章优先显示当前会话选择解析出的档名与模型，回落到 /status
  const profilesPayload = useUiStore((s) => s.profilesPayload);
  const selProfile = profilesPayload?.profiles.find((p) => p.id === (selection.profileId ?? profilesPayload?.defaultProfile));
  const selModel = selection.model ?? selProfile?.models[0]?.name ?? "";
  const badgeText = selProfile
    ? `${selProfile.name || selProfile.provider}▸${selModel || "未选"}`
    : `${status?.provider ?? "?"}/${status?.model || "未配置"}`;
  // 仅在目录为空且后端也未配置模型时才警示（有目录即视为可用，哪怕尚未就绪）
  const unconfigured = !selProfile && !status?.model && !(profilesPayload && profilesPayload.profiles.length > 0);
  return (
    <header data-tauri-drag-region className="flex h-12 shrink-0 items-center gap-2 border-b border-border bg-surface px-4">
      <FolderOpen size={16} className="shrink-0 text-muted" />
      <span data-tauri-drag-region className="truncate text-sm text-ink" title={title}>
        {title}
      </span>
      <div className="ml-auto flex shrink-0 items-center gap-2">
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
        <span className={`h-2 w-2 rounded-full ${connected ? "bg-success" : "bg-danger"}`} title={connected ? "已连接" : "未连接"} />
      </div>
    </header>
  );
}
