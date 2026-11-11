import { useProjectStore } from "../../store/useProjectStore";
import { Button } from "../ui/button";
import { ScrollArea } from "../ui/scroll-area";
import { FolderOpen, Plus, Search, X } from "lucide-react";
import { SessionList } from "../sessions/SessionList";
import { useState } from "react";

export function Sidebar({
  onNewSession,
  onCloseProject,
}: {
  onNewSession: () => void;
  onCloseProject: () => void;
}) {
  const store = useProjectStore();
  const workspace = store((s) => s.status?.workspace);
  const sessions = store((s) => s.sessions);
  const [q, setQ] = useState("");

  const filtered = sessions.filter((s) => s.title.toLowerCase().includes(q.toLowerCase()));

  return (
    <aside className="flex w-64 shrink-0 flex-col border-r border-border bg-surface">
      <div className="flex items-center gap-2 border-b border-border p-3">
        <div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border border-border px-2 py-1.5 text-muted" title={workspace}>
          <FolderOpen size={14} className="shrink-0" />
          <span className="truncate text-sm">{workspace ? workspace.split("/").pop() : "项目"}</span>
        </div>
        <Button size="sm" className="bg-primary-deep text-white hover:bg-primary" onClick={onNewSession} title="新建会话">
          <Plus size={16} />
        </Button>
        <Button
          size="sm"
          variant="outline"
          className="shrink-0 border-danger/40 text-danger hover:bg-danger/10"
          onClick={onCloseProject}
          title="关闭此项目（进程退出，会话记录保留）"
        >
          <X size={16} />
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
