import { useAppStore } from "../../store/useAppStore";
import { pickWorkspace } from "../../lib/tauri";
import { Button } from "../ui/button";
import { ScrollArea } from "../ui/scroll-area";
import { FolderOpen, Plus, Search } from "lucide-react";
import { SessionList } from "../sessions/SessionList";
import { useState } from "react";

export function Sidebar({ onNewSession }: { onNewSession: () => void }) {
  const workspace = useAppStore((s) => s.status?.workspace);
  const sessions = useAppStore((s) => s.sessions);
  const [q, setQ] = useState("");

  const filtered = sessions.filter((s) => s.title.toLowerCase().includes(q.toLowerCase()));

  return (
    <aside className="flex w-64 shrink-0 flex-col border-r border-border bg-surface">
      <div className="flex items-center gap-2 border-b border-border p-3">
        <Button variant="outline" size="sm" className="flex-1 justify-start gap-2 border-border text-muted" onClick={() => pickWorkspace()}>
          <FolderOpen size={14} /> <span className="truncate">{workspace ? workspace.split("/").pop() : "选择项目"}</span>
        </Button>
        <Button size="sm" className="bg-primary-deep text-white hover:bg-primary" onClick={onNewSession} title="新建会话">
          <Plus size={16} />
        </Button>
      </div>
      <div className="flex items-center gap-2 px-3 py-2 text-muted">
        <Search size={14} />
        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="搜索会话"
          className="w-full bg-transparent text-xs text-ink outline-none placeholder:text-muted"
        />
      </div>
      <ScrollArea className="flex-1">
        <SessionList sessions={filtered} />
      </ScrollArea>
    </aside>
  );
}