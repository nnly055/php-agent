import type { SessionMeta } from "../../lib/api";
import { useAppStore, type ChatMessage, type ToolCard } from "../../store/useAppStore";
import { loadSession, deleteSession, listSessions } from "../../lib/api";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "../ui/dropdown-menu";
import { MoreVertical, Trash2, MessageSquare } from "lucide-react";
import { cn } from "../../lib/utils";

function toChatMessages(messages: any[]): ChatMessage[] {
  // 把归一化历史转成只读 transcript（user 文本 + assistant 文本/工具）
  const out: ChatMessage[] = [];
  for (const m of messages) {
    if (m.role === "user" && Array.isArray(m.content)) {
      const text = m.content.filter((b: any) => b.type === "text").map((b: any) => b.text).join("");
      if (text) out.push({ id: `h${out.length}`, role: "user", text, tools: [] });
    } else if (m.role === "assistant" && Array.isArray(m.content)) {
      const text = m.content.filter((b: any) => b.type === "text").map((b: any) => b.text).join("");
      const tools: ToolCard[] = m.content.filter((b: any) => b.type === "tool_use").map((b: any) => ({ id: b.id, name: b.name, input: b.input, status: "ok" }));
      out.push({ id: `h${out.length}`, role: "assistant", text, tools });
    }
  }
  return out;
}

export function SessionItem({ meta }: { meta: SessionMeta }) {
  const active = useAppStore((s) => s.activeSession) === meta.id;

  async function open() {
    const { messages } = await loadSession(meta.id);
    useAppStore.getState().setActiveSession(meta.id, toChatMessages(messages));
  }
  async function remove() {
    await deleteSession(meta.id);
    const { sessions } = await listSessions();
    useAppStore.getState().setSessions(sessions);
    if (active) useAppStore.getState().setActiveSession("", []);
  }

  return (
    <div className={cn("group mx-2 mb-1 flex items-center gap-2 rounded-lg px-2 py-2 transition-colors", active ? "bg-primary/15" : "hover:bg-elevated")}>
      <button onClick={open} className="flex min-w-0 flex-1 items-center gap-2 text-left">
        <MessageSquare size={14} className={active ? "text-primary" : "text-muted"} />
        <span className={cn("truncate text-xs", active ? "text-ink" : "text-muted")}>{meta.title}</span>
      </button>
      <DropdownMenu>
        <DropdownMenuTrigger aria-label="会话操作" className="opacity-0 transition-opacity focus-visible:opacity-100 group-hover:opacity-100">
          <MoreVertical size={14} className="text-muted" />
        </DropdownMenuTrigger>
        <DropdownMenuContent className="border-border bg-elevated">
          <DropdownMenuItem className="text-danger focus:bg-danger/10 focus:text-danger" onClick={remove}>
            <Trash2 size={14} /> 删除会话
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}