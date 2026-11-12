import { useCallback, useEffect, useRef, useState } from "react";
import { AppShell } from "./components/layout/AppShell";
import { ConnectionBanner } from "./components/ConnectionBanner";
import { EmptyProjects, ProjectTabs } from "./components/layout/ProjectTabs";
import { ProjectProvider } from "./store/useProjectStore";
import { createProjectStore, type ProjectStore } from "./store/createProjectStore";
import { useBackendLiveness } from "./hooks/useBackendLiveness";
import { SettingsDialog } from "./components/settings/SettingsDialog";
import { useUiStore } from "./store/useUiStore";
import {
  addProject as addProjectDialog,
  closeProject,
  getLastSession,
  listProjects,
  onBackendEvent,
  openProject,
  restartSession,
  type AgentConfig,
  type OpenedSession,
} from "./lib/tauri";
import { getStatus, listSessions } from "./lib/api";

/** 一个打开的项目标签：独立 store（独立 sidecar 进程）+ 项目路径。 */
type ProjectTab = { id: string; path: string; store: ProjectStore };

function projectName(path: string): string {
  const parts = path.split("/").filter(Boolean);
  return parts[parts.length - 1] ?? path;
}

/** 当前项目的完整工作区（会话侧栏 + 聊天视图 + 授权弹窗），挂载时拉取 status/sessions。 */
function ProjectWorkspace({ tab, onClose }: { tab: ProjectTab; onClose: () => void }) {
  const store = tab.store;
  useBackendLiveness(true);

  const refresh = useCallback(async () => {
    const url = store.getState().backendUrl;
    const [status, sessions] = await Promise.all([getStatus(url), listSessions(url)]);
    store.getState().setStatus(status);
    store.getState().setSessions(sessions.sessions);
    store.getState().setConnected(true);
  }, [store]);

  useEffect(() => {
    refresh().catch(() => store.getState().setConnected(false));
    // 仅挂载时拉一次；后续由 liveness 轮询与回合结束刷新维护
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab.id]);

  function newSession() {
    store.getState().setActiveSession(`web-${Date.now().toString(36)}`, []);
  }

  return (
    <>
      <ConnectionBanner onRetry={refresh} />
      <AppShell onNewSession={newSession} onCloseProject={onClose} />
    </>
  );
}

export default function App() {
  const [tabs, setTabs] = useState<ProjectTab[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);
  const [projects, setProjects] = useState<string[]>([]);
  const [booted, setBooted] = useState(false);
  const [booting, setBooting] = useState<string | null>(null);

  // tabs 的渲染期镜像：backend 事件回调（effect 只挂一次）读取最新 tabs，避免闭包过期
  const tabsRef = useRef<ProjectTab[]>([]);
  tabsRef.current = tabs;

  // 项目标签打开（幂等：同一路径复用已有 tab）
  const openTab = useCallback((path: string, opened: OpenedSession) => {
    setTabs((prev) => {
      const existing = prev.find((t) => t.id === opened.sessionId);
      if (existing) {
        setActiveId(opened.sessionId);
        return prev;
      }
      const store = createProjectStore();
      store.getState().setSessionId(opened.sessionId);
      store.getState().setBackend(`http://127.0.0.1:${opened.port}`);
      return [...prev, { id: opened.sessionId, path, store }];
    });
    setActiveId(opened.sessionId);
    setProjects((prev) => (prev.includes(path) ? prev : [...prev, path]));
  }, []);

  // 启动：读项目列表 + 懒恢复最近会话（仅恢复一个，其余点标签时再拉起）
  useEffect(() => {
    (async () => {
      try {
        const [projects, last] = await Promise.all([listProjects(), getLastSession()]);
        setProjects(projects);
        if (last.workspace && last.sessionId) {
          await openTab(last.workspace, await openProject(last.workspace));
        }
      } catch {
        /* 首次启动无持久化属正常；错误在 openProject 的 error 事件里可见 */
      } finally {
        setBooted(true);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // backend 事件路由到对应项目的 store
  useEffect(() => {
    let unlistens: Array<() => void> = [];
    onBackendEvent((e) => {
      if (e.kind === "ready") {
        // 重启（保存配置/横幅重连）产生新 sessionId+端口：按 workspace 重绑 tab 与 store，
        // 避免继续向旧会话/旧端口发请求。首开路径 tab.id 已一致，此处幂等 no-op。
        const t = tabsRef.current.find((x) => x.path === e.workspace && x.id !== e.sessionId);
        if (t) {
          t.store.getState().setSessionId(e.sessionId);
          t.store.getState().setBackend(`http://127.0.0.1:${e.port}`);
          setTabs((prev) => prev.map((x) => (x === t ? { ...x, id: e.sessionId } : x)));
        }
      } else if (e.kind === "disconnected") {
        setTabs((prev) => {
          const t = prev.find((x) => x.id === e.sessionId);
          t?.store.getState().setConnected(false);
          return prev;
        });
      } else if (e.kind === "error" && e.sessionId) {
        setTabs((prev) => {
          const t = prev.find((x) => x.id === e.sessionId);
          t?.store.getState().setError(e.message);
          return prev;
        });
      }
    }).then((fns) => { unlistens = fns; });
    return () => { unlistens.forEach((f) => f()); };
  }, []);

  const openPath = useCallback(async (path: string) => {
    setBooting(path);
    try {
      const opened = await openProject(path);
      openTab(path, opened);
    } finally {
      setBooting(null);
    }
  }, [openTab]);

  const addProject = useCallback(async () => {
    setBooting("__dialog__");
    try {
      const opened = await addProjectDialog();
      if (opened) openTab(opened.workspace, opened);
    } finally {
      setBooting(null);
    }
  }, [openTab]);

  // 保存全局配置（持久化已由对话框完成）：重启所有运行中的项目并重绑新 sessionId/端口。
  // 重启失败的项目置 error + disconnected（ConnectionBanner 可重试），不影响其他项目。
  const applyConfig = useCallback(async (_cfg: AgentConfig) => {
    for (const tab of tabs) {
      try {
        const opened = await restartSession(tab.id);
        tab.store.getState().setBackend(`http://127.0.0.1:${opened.port}`);
        tab.store.getState().setSessionId(opened.sessionId);
        tab.store.getState().setError(null);
        // tab.id 变更会触发 ProjectWorkspace 的 refresh（重拉 status/sessions）
        const wasActive = tab.id === activeId;
        setTabs((prev) => prev.map((t) => (t.id === tab.id ? { ...t, id: opened.sessionId } : t)));
        // activeId 同步重绑：否则活跃 tab 换 id 后 activeTab 判空，界面回落到空项目页
        if (wasActive) setActiveId(opened.sessionId);
      } catch (e: any) {
        tab.store.getState().setConnected(false);
        tab.store.getState().setError(e?.message ?? "重启失败");
      }
    }
    useUiStore.getState().setSettingsOpen(false);
  }, [tabs, activeId]);

  const closeTab = useCallback(async (tab: ProjectTab) => {
    try { await closeProject(tab.id); } catch { /* 进程可能已退出 */ }
    setTabs((prev) => {
      const next = prev.filter((t) => t.id !== tab.id);
      setActiveId((cur) => (cur === tab.id ? next[next.length - 1]?.id ?? null : cur));
      return next;
    });
  }, []);

  const activeTab = tabs.find((t) => t.id === activeId) ?? null;

  return (
    <div className="flex h-screen flex-col bg-bg text-ink">
      <ProjectTabs
        tabs={tabs.map((t) => ({ id: t.id, name: projectName(t.path), path: t.path }))}
        activeId={activeId}
        projects={projects.filter((p) => !tabs.some((t) => t.path === p))}
        onSelect={setActiveId}
        onClose={(id) => { const t = tabs.find((x) => x.id === id); if (t) closeTab(t); }}
        onAdd={addProject}
        onOpen={(path) => openPath(path)}
        booting={booting}
      />
      <div className="flex min-h-0 flex-1 flex-col">
        {activeTab ? (
          <ProjectProvider store={activeTab.store}>
            <ProjectWorkspace tab={activeTab} onClose={() => { const t = activeTab; if (t) closeTab(t); }} />
          </ProjectProvider>
        ) : booted ? (
          <EmptyProjects
            projects={projects}
            booting={booting}
            onAdd={addProject}
            onOpen={(path) => openPath(path)}
            onConfigure={() => useUiStore.getState().setSettingsOpen(true)}
          />
        ) : (
          <div className="grid h-full place-items-center animate-pulse font-mono text-sm text-muted">正在启动…</div>
        )}
      </div>
      <SettingsDialog
        testUrl={activeTab?.store.getState().connected ? activeTab.store.getState().backendUrl : null}
        onSaved={applyConfig}
      />
    </div>
  );
}
