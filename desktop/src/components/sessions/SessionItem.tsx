import { useState } from "react";
import type { SessionMeta } from "../../lib/api";
import { relativeTime } from "../../lib/relativeTime";
import { type ChatMessage, type ToolCard } from "../../store/useAppStore";
import { useProjectStore } from "../../store/useProjectStore";
import { loadSession, deleteSession, listSessions } from "../../lib/api";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "../ui/dropdown-menu";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "../ui/alert-dialog";
import { MoreVertical, Trash2, MessageSquare } from "lucide-react";
import { cn } from "../../lib/utils";

function toChatMessages(messages: any[]): ChatMessage[] {
  // 把归一化历史转成只读 transcript（user 文本 + assistant 文本/工具）
  const out: ChatMessage[] = [];
  if (!Array.isArray(messages)) return out;
  for (const m of messages) {
    if (!m || !Array.isArray(m.content)) continue;
    if (m.role === "user") {
      const text = m.content.filter((b: any) => b && b.type === "text").map((b: any) => b.text ?? "").join("");
      // 提取归一化 image 块（media_type/data），纯图消息也要保留
      const images = m.content.filter((b: any) => b?.type === "image").map((b: any) => ({ mediaType: b.media_type, data: b.data }));
      if (text || images.length) out.push({ id: `h${out.length}`, role: "user", text, tools: [], ...(images.length ? { images } : {}) });
    } else if (m.role === "assistant") {
      const text = m.content.filter((b: any) => b && b.type === "text").map((b: any) => b.text ?? "").join("");
      const tools: ToolCard[] = m.content.filter((b: any) => b && b.type === "tool_use").map((b: any) => ({ id: b.id, name: b.name, input: b.input, status: "ok" }));
      // 既无文本也无工具的 assistant 消息跳过，避免空气泡
      if (text || tools.length) out.push({ id: `h${out.length}`, role: "assistant", text, tools });
    }
  }
  return out;
}

export function SessionItem({ meta, onActivate }: { meta: SessionMeta; onActivate?: () => void }) {
  const store = useProjectStore();
  const active = store((s) => s.activeSession) === meta.id;
  const [confirmOpen, setConfirmOpen] = useState(false);

  async function open() {
    // 跨项目场景：先切激活项目，再加载会话（否则请求打到错误 sidecar）
    onActivate?.();
    try {
      const { messages } = await loadSession(store.getState().backendUrl, meta.id);
      store.getState().setActiveSession(meta.id, toChatMessages(messages));
      // 恢复该会话落库时的平台/模型选择（meta 来自 /sessions 列表项）
      if (meta.profileId || meta.model) {
        store.getState().setSelection({ profileId: meta.profileId ?? null, model: meta.model ?? null });
      }
    } catch (e: any) {
      store.getState().setError(e?.message ?? "加载会话失败");
    }
  }
  async function remove() {
    const baseUrl = store.getState().backendUrl;
    try {
      await deleteSession(baseUrl, meta.id);
      const { sessions } = await listSessions(baseUrl);
      store.getState().setSessions(sessions);
      if (active) store.getState().setActiveSession("", []);
    } catch (e: any) {
      store.getState().setError(e?.message ?? "删除会话失败");
    }
  }

  return (
    <div className={cn("group mx-2 mb-1 flex items-center gap-2 rounded-lg px-2 py-2 transition-colors", active ? "bg-primary/15" : "hover:bg-elevated")}>
      <button onClick={open} className="flex min-w-0 flex-1 items-center gap-2 text-left">
        <MessageSquare size={14} className={active ? "text-primary" : "text-muted"} />
        <span className={cn("truncate text-xs", active ? "text-ink" : "text-muted")}>{meta.title}</span>
      </button>
      <span className="shrink-0 text-[11px] text-muted">{relativeTime(meta.updatedAt)}</span>
      <DropdownMenu>
        <DropdownMenuTrigger aria-label="会话操作" className="opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100 data-[state=open]:opacity-100">
          <MoreVertical size={14} className="text-muted" />
        </DropdownMenuTrigger>
        <DropdownMenuContent className="border-border bg-elevated">
          <DropdownMenuItem className="text-danger focus:bg-danger/10 focus:text-danger" onClick={() => setConfirmOpen(true)}>
            <Trash2 size={14} /> 删除会话
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent className="border-border bg-surface">
          <AlertDialogHeader>
            <AlertDialogTitle className="text-ink">删除会话</AlertDialogTitle>
            <AlertDialogDescription className="text-muted">
              确定删除「{meta.title}」？此操作不可撤销。
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel className="border-border bg-elevated text-ink hover:bg-elevated/80 hover:text-ink">取消</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={async () => {
                await remove();
                setConfirmOpen(false);
              }}
            >
              删除
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}