import { useEffect } from "react";
import { useAppStore } from "../../store/useAppStore";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from "../ui/dialog";
import { Button } from "../ui/button";
import { DiffView } from "../chat/DiffView";
import { ShieldAlert } from "lucide-react";

export function ConfirmDialog({ onAnswer }: { onAnswer: (d: "y" | "n" | "a") => void }) {
  const pending = useAppStore((s) => s.pendingConfirm);

  useEffect(() => {
    if (!pending) return;
    function onKey(e: KeyboardEvent) {
      const k = e.key.toLowerCase();
      if (k === "y") onAnswer("y");
      else if (k === "n" || k === "escape") onAnswer("n");
      else if (k === "a") onAnswer("a");
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [pending, onAnswer]);

  if (!pending) return null;
  const isWrite = pending.tool === "write_file";

  return (
    <Dialog open onOpenChange={(o) => { if (!o) onAnswer("n"); }}>
      <DialogContent className="max-w-2xl border-border bg-elevated text-ink">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-ink">
            <ShieldAlert size={18} className="text-warning" /> 工具授权请求
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-3">
          <div className="flex items-center gap-2">
            <span className="rounded bg-primary/15 px-2 py-0.5 font-mono text-sm text-primary">{pending.tool}</span>
            {isWrite && <span className="font-mono text-xs text-muted">{pending.input?.path}</span>}
          </div>
          {pending.preview ? (
            pending.preview.startsWith("新文件") || pending.preview.startsWith("(") ? (
              <div className="rounded-lg border border-border bg-bg p-3 text-sm text-muted">{pending.preview}</div>
            ) : (
              <DiffView text={pending.preview} />
            )
          ) : (
            <pre className="overflow-x-auto rounded-lg border border-border bg-bg p-3 font-mono text-[12px] text-ink/90">
              {JSON.stringify(pending.input, null, 2)}
            </pre>
          )}
          <p className="text-xs text-muted">快捷键：Y 允许 · N 拒绝 · A 本次会话总是允许 · Esc 拒绝</p>
        </div>
        <DialogFooter className="gap-2">
          <Button variant="outline" className="border-danger/40 text-danger hover:bg-danger/10" onClick={() => onAnswer("n")}>拒绝 (N)</Button>
          <Button variant="outline" className="border-border text-muted" onClick={() => onAnswer("a")}>总是允许 (A)</Button>
          <Button className="bg-primary-deep text-white hover:bg-primary" onClick={() => onAnswer("y")}>允许 (Y)</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}