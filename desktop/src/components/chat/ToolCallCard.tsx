import { useState } from "react";
import type { ToolCard } from "../../store/useAppStore";
import { DiffView } from "./DiffView";
import { looksLikeDiff } from "../../lib/diff";
import { cn } from "../../lib/utils";
import { ChevronRight, Wrench, Loader2, Check, X, AlertTriangle } from "lucide-react";

const STATUS = {
  running: { border: "border-l-warning", icon: <Loader2 size={13} className="animate-spin text-warning" />, label: "运行中" },
  ok: { border: "border-l-success", icon: <Check size={13} className="text-success" />, label: "完成" },
  denied: { border: "border-l-danger", icon: <X size={13} className="text-danger" />, label: "已拒绝" },
  error: { border: "border-l-danger", icon: <AlertTriangle size={13} className="text-danger" />, label: "错误" },
} as const;

export function ToolCallCard({ tool }: { tool: ToolCard }) {
  const [open, setOpen] = useState(false);
  const s = STATUS[tool.status];
  const args = JSON.stringify(tool.input ?? {});
  const result = tool.result ?? "";
  const isDiff = looksLikeDiff(result);

  return (
    <div className={cn("my-2 rounded-lg border border-border border-l-[3px] bg-elevated/60", s.border)}>
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-primary/60"
      >
        <ChevronRight size={14} className={cn("shrink-0 text-muted transition-transform", open && "rotate-90")} />
        <Wrench size={13} className="shrink-0 text-warning" />
        <span className="shrink-0 font-mono text-[12.5px] font-semibold text-ink">{tool.name}</span>
        <span className="truncate font-mono text-[11px] text-muted">{args.slice(0, 80)}</span>
        <span className="ml-auto flex shrink-0 items-center gap-1 text-[11px] text-muted">{s.icon} {s.label}</span>
      </button>
      {open && (
        <div className="space-y-2 border-t border-border px-3 py-2">
          <div>
            <div className="mb-1 text-[10px] uppercase tracking-wide text-muted">参数</div>
            <pre className="overflow-x-auto rounded bg-bg p-2 font-mono text-[11.5px] text-ink/90">{JSON.stringify(tool.input ?? {}, null, 2)}</pre>
          </div>
          {result && (
            <div>
              <div className="mb-1 text-[10px] uppercase tracking-wide text-muted">结果</div>
              {isDiff ? <DiffView text={result} /> : (
                <pre className={cn("overflow-x-auto rounded bg-bg p-2 font-mono text-[11.5px]", tool.status === "error" || tool.status === "denied" ? "text-danger" : "text-success")}>
                  {result.slice(0, 4000)}
                </pre>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
