import { FolderOpen, Plus, Settings } from "lucide-react";

/** 无任何已打开项目时的欢迎屏：项目列表 + 添加入口。 */
export function EmptyProjects({
  projects,
  booting,
  onAdd,
  onOpen,
  onConfigure,
}: {
  projects: string[];
  booting: string | null;
  onAdd: () => void;
  onOpen: (path: string) => void;
  onConfigure: () => void;
}) {
  return (
    <div className="flex h-full flex-col">
      {/* macOS 无框化下 0 项目状态无侧栏，此行是窗口唯一的拖拽区 */}
      <div data-tauri-drag-region className="h-12 shrink-0" />
      <div className="grid flex-1 place-items-center">
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
          <button
            className="flex items-center gap-2 rounded-lg border border-border bg-surface px-5 py-2.5 text-sm text-muted transition-colors hover:border-primary hover:text-ink"
            onClick={onConfigure}
          >
            <Settings size={16} /> 配置 API
          </button>
          <p className="text-xs text-muted">选择一个项目目录，agent 的文件操作与命令都限定在该目录内。</p>
        </div>
      </div>
    </div>
  );
}

/** 启动屏（项目列表尚未加载完成的占位）；整屏为拖拽区。 */
export function BootScreen() {
  return (
    <div data-tauri-drag-region className="grid h-full place-items-center animate-pulse font-mono text-sm text-muted">
      正在启动…
    </div>
  );
}
