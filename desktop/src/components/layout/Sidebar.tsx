import { useState } from "react";
import { useStore } from "zustand";
import type { ProjectStore } from "../../store/createProjectStore";
import { ProjectProvider, useProjectStore, getDefaultProjectStore } from "../../store/useProjectStore";
import { ScrollArea } from "../ui/scroll-area";
import { SessionList } from "../sessions/SessionList";
import { ChevronDown, ChevronRight, FolderOpen, Plus, Search, Settings, X } from "lucide-react";
import { useUiStore } from "../../store/useUiStore";
import { cn } from "../../lib/utils";

type SidebarTab = { id: string; path: string; store: ProjectStore };

const baseName = (path: string) => path.split("/").filter(Boolean).pop() ?? path;

/** 组内会话列表：读所在 ProjectProvider 的 store，按 q 过滤；点击会话先经 onActivate 切项目。 */
function GroupSessions({ onActivate, q }: { onActivate: () => void; q: string }) {
  const store = useProjectStore();
  const sessions = store((s) => s.sessions);
  const filtered = sessions.filter((s) => s.title.toLowerCase().includes(q.toLowerCase()));
  return <SessionList sessions={filtered} onActivate={onActivate} />;
}

/** 侧栏底部：版本号 + 设置入口。版本经 useStore 订阅激活项目 store（status 由 refresh 异步填充）。 */
function SidebarFooter({ store }: { store: ProjectStore | null }) {
  const version = useStore(store ?? getDefaultProjectStore(), (s) => s.status?.version);
  return (
    <div className="flex items-center border-t border-border px-3 py-2">
      <span className="font-mono text-[11px] text-muted">v{version ?? ""}</span>
      <button
        aria-label="打开设置"
        className="ml-auto rounded p-0.5 text-muted transition-colors hover:bg-elevated hover:text-ink"
        onClick={() => useUiStore.getState().setSettingsOpen(true)}
        title="配置 API"
      >
        <Settings size={14} />
      </button>
    </div>
  );
}

export function Sidebar({
  tabs,
  activeId,
  projects,
  booting,
  onSelectTab,
  onAddProject,
  onOpenProject,
  onCloseProject,
  onNewSession,
}: {
  tabs: SidebarTab[];
  activeId: string | null;
  projects: string[];
  booting: string | null;
  onSelectTab: (id: string) => void;
  onAddProject: () => void;
  onOpenProject: (path: string) => void;
  onCloseProject: (id: string) => void;
  onNewSession: () => void;
}) {
  const [q, setQ] = useState("");
  // 折叠状态按项目路径为键：tab id 会被 sidecar 重启重绑，路径稳定
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
  const activeStore = tabs.find((t) => t.id === activeId)?.store ?? null;
  const unopened = projects.filter((p) => !tabs.some((t) => t.path === p));

  const groupHeader = (tab: SidebarTab) => {
    const name = baseName(tab.path);
    const isCollapsed = collapsed[tab.path] ?? false;
    return (
      <div
        className={cn(
          "group flex cursor-pointer items-center gap-1.5 rounded-lg px-2 py-1.5 text-xs transition-colors",
          tab.id === activeId ? "bg-primary/10 text-ink" : "text-muted hover:bg-elevated hover:text-ink"
        )}
        onClick={() => onSelectTab(tab.id)}
        title={tab.path}
      >
        <button
          aria-label={`折叠项目 ${name}`}
          className="shrink-0 text-muted hover:text-ink"
          onClick={(e) => {
            e.stopPropagation();
            setCollapsed((prev) => ({ ...prev, [tab.path]: !isCollapsed }));
          }}
        >
          {isCollapsed ? <ChevronRight size={13} /> : <ChevronDown size={13} />}
        </button>
        <FolderOpen size={13} className="shrink-0" />
        <span className="min-w-0 flex-1 truncate">{name}</span>
        {booting === tab.path && <span className="animate-pulse text-[11px]">启动中…</span>}
        <button
          aria-label={`关闭项目 ${name}`}
          className="shrink-0 rounded p-0.5 text-muted opacity-0 transition-opacity hover:text-danger group-hover:opacity-100"
          onClick={(e) => {
            e.stopPropagation();
            onCloseProject(tab.id);
          }}
        >
          <X size={12} />
        </button>
      </div>
    );
  };

  return (
    <aside className="flex w-64 shrink-0 flex-col border-r border-border bg-surface">
      {/* 红绿灯预留行（macOS Overlay 模式下系统红绿灯悬于此处）；整行拖拽窗口 */}
      <div data-tauri-drag-region className="h-12 shrink-0" />
      <div className="px-3 pb-2">
        <button
          className="flex w-full items-center justify-center gap-1.5 rounded-lg bg-primary-deep py-2 text-sm font-medium text-white transition-colors hover:bg-primary"
          onClick={onNewSession}
        >
          <Plus size={15} /> 新建会话
        </button>
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
      <div className="flex items-center px-3 py-1 text-xs text-muted">
        <span>项目</span>
        <button
          aria-label="添加项目"
          className="ml-auto rounded p-0.5 text-muted transition-colors hover:bg-elevated hover:text-ink"
          onClick={onAddProject}
          title="添加项目目录（每个项目一个独立 PHP 进程）"
        >
          <Plus size={13} />
        </button>
      </div>
      <ScrollArea className="flex-1">
        {tabs.map((tab) => {
          const isCollapsed = collapsed[tab.path] ?? false;
          return (
            <div key={tab.id} className="px-2 pb-1">
              {groupHeader(tab)}
              {!isCollapsed && (
                <div className="pl-3">
                  <ProjectProvider store={tab.store}>
                    <GroupSessions onActivate={() => onSelectTab(tab.id)} q={q} />
                  </ProjectProvider>
                </div>
              )}
            </div>
          );
        })}
        {unopened.map((p) => (
          <button
            key={p}
            disabled={booting !== null}
            className="mx-2 mb-1 flex w-[calc(100%-1rem)] items-center gap-1.5 rounded-lg px-2 py-1.5 text-left text-xs text-muted transition-colors hover:bg-elevated hover:text-ink disabled:opacity-50"
            onClick={() => onOpenProject(p)}
            title={p}
          >
            <FolderOpen size={13} className="shrink-0" />
            <span className="min-w-0 flex-1 truncate">{baseName(p)}</span>
            {booting === p && <span className="animate-pulse text-[11px]">启动中…</span>}
          </button>
        ))}
      </ScrollArea>
      <SidebarFooter store={activeStore} />
    </aside>
  );
}
