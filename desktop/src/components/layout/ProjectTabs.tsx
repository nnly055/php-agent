import { FolderOpen, FolderPlus, Plus, X } from "lucide-react";
import { cn } from "../../lib/utils";

/** 顶部项目标签条：已打开项目 = 标签（可关闭），「+」添加新项目，折叠列表恢复已添加项目。 */
export function ProjectTabs({
  tabs,
  activeId,
  projects,
  onSelect,
  onClose,
  onAdd,
  onOpen,
  booting,
}: {
  tabs: Array<{ id: string; name: string; path: string }>;
  activeId: string | null;
  projects: string[];
  onSelect: (id: string) => void;
  onClose: (id: string) => void;
  onAdd: () => void;
  onOpen: (path: string) => void;
  booting: string | null;
}) {
  return (
    <div className="flex h-9 shrink-0 items-center gap-1 border-b border-border bg-surface px-2">
      {tabs.map((t) => (
        <div
          key={t.id}
          className={cn(
            "group flex items-center gap-2 rounded-t-lg border-x border-t px-3 py-1.5 text-xs transition-colors",
            t.id === activeId
              ? "border-border bg-bg text-ink"
              : "border-transparent text-muted hover:bg-elevated hover:text-ink"
          )}
        >
          <button className="max-w-40 truncate" onClick={() => onSelect(t.id)} title={t.path}>
            {t.name}
          </button>
          <button
            aria-label={`关闭项目 ${t.name}`}
            className="rounded p-0.5 text-muted opacity-0 transition-opacity hover:bg-elevated hover:text-danger group-hover:opacity-100"
            onClick={(e) => { e.stopPropagation(); onClose(t.id); }}
          >
            <X size={12} />
          </button>
        </div>
      ))}

      <button
        className="ml-1 flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs text-muted transition-colors hover:bg-elevated hover:text-ink"
        onClick={onAdd}
        title="添加项目目录（每个项目一个独立 PHP 进程）"
      >
        <FolderPlus size={13} /> 添加项目
      </button>

      {projects.length > 0 && (
        <div className="group relative">
          <button className="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted transition-colors hover:bg-elevated hover:text-ink" title="打开已添加的项目">
            <FolderOpen size={13} /> <span className="font-mono">{projects.length}</span>
          </button>
          <div className="invisible absolute left-0 z-20 mt-1 w-72 rounded-lg border border-border bg-elevated py-1 shadow-lg group-hover:visible">
            {projects.map((p) => (
              <button
                key={p}
                disabled={booting !== null}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-ink hover:bg-primary/10 disabled:opacity-50"
                onClick={() => onOpen(p)}
                title={p}
              >
                <FolderOpen size={13} className="shrink-0 text-muted" />
                <span className="truncate">{p}</span>
                {booting === p && <span className="ml-auto animate-pulse text-muted">启动中…</span>}
              </button>
            ))}
          </div>
        </div>
      )}

      {booting !== null && booting !== "__dialog__" && (
        <span className="ml-1 animate-pulse font-mono text-[11px] text-muted">
          正在启动 {booting.split("/").pop()}…
        </span>
      )}
    </div>
  );
}

/** 无任何已打开项目时的欢迎屏：项目列表 + 添加入口。 */
export function EmptyProjects({
  projects,
  booting,
  onAdd,
  onOpen,
}: {
  projects: string[];
  booting: string | null;
  onAdd: () => void;
  onOpen: (path: string) => void;
}) {
  return (
    <div className="grid h-full place-items-center">
      <div className="flex max-w-md flex-col items-center gap-6 text-center">
        <span className="grid h-16 w-16 place-items-center rounded-2xl bg-primary-deep font-mono text-xl font-bold text-white">php</span>
        <div>
          <h1 className="text-lg font-semibold text-ink">PHP Agent 桌面版</h1>
          <p className="mt-1 text-sm text-muted">每个项目一个独立 PHP 进程：工作区、会话与授权互不干扰。</p>
        </div>
        <div className="w-full space-y-2">
          {projects.map((p) => (
            <button
              key={p}
              disabled={booting !== null}
              className="flex w-full items-center gap-2 rounded-lg border border-border bg-surface px-4 py-3 text-left text-sm text-ink transition-colors hover:border-primary hover:bg-elevated disabled:opacity-50"
              onClick={() => onOpen(p)}
              title={p}
            >
              <FolderOpen size={16} className="shrink-0 text-primary" />
              <span className="truncate">{p}</span>
              {booting === p && <span className="ml-auto animate-pulse text-xs text-muted">启动中…</span>}
            </button>
          ))}
        </div>
        <button
          disabled={booting !== null}
          className="flex items-center gap-2 rounded-lg bg-primary-deep px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-primary disabled:opacity-50"
          onClick={onAdd}
        >
          <Plus size={16} /> 添加项目目录
        </button>
        <p className="text-xs text-muted">选择一个项目目录，agent 的文件操作与命令都限定在该目录内。</p>
      </div>
    </div>
  );
}
