import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { Sidebar } from "./Sidebar";
import { createProjectStore } from "../../store/createProjectStore";
import { useUiStore } from "../../store/useUiStore";
import type { SessionMeta } from "../../lib/api";

const meta = (id: string, title: string): SessionMeta => ({ id, title, createdAt: 0, updatedAt: 0, messageCount: 1 });
const mkTab = (id: string, path: string, sessions: SessionMeta[]) => {
  const store = createProjectStore();
  store.getState().setSessions(sessions);
  return { id, path, store };
};

describe("Sidebar 多项目分组", () => {
  const base = {
    activeId: "t1", projects: [] as string[], booting: null as string | null,
    onSelectTab: vi.fn(), onAddProject: vi.fn(), onOpenProject: vi.fn(),
    onCloseProject: vi.fn(), onNewSession: vi.fn(),
  };
  beforeEach(() => {
    vi.clearAllMocks();
    useUiStore.getState().setSettingsOpen(false);
  });

  it("每个 tab 一组：项目名为路径末段，组内挂各自会话，数据不串", () => {
    const t1 = mkTab("t1", "/w/php-agent", [meta("s1", "会话A")]);
    const t2 = mkTab("t2", "/w/qianyouyou", [meta("s2", "会话B")]);
    render(<Sidebar {...base} tabs={[t1, t2]} />);
    expect(screen.getByText("php-agent")).toBeInTheDocument();
    expect(screen.getByText("qianyouyou")).toBeInTheDocument();
    expect(screen.getByText("会话A")).toBeInTheDocument();
    expect(screen.getByText("会话B")).toBeInTheDocument();
  });

  it("点击其他项目的会话 → onSelectTab(该 tab id)（先切项目）", () => {
    const t1 = mkTab("t1", "/w/php-agent", [meta("s1", "会话A")]);
    const t2 = mkTab("t2", "/w/qianyouyou", [meta("s2", "会话B")]);
    render(<Sidebar {...base} tabs={[t1, t2]} />);
    fireEvent.click(screen.getByRole("button", { name: /会话B/ }));
    expect(base.onSelectTab).toHaveBeenCalledWith("t2");
  });

  it("搜索跨项目过滤", () => {
    const t1 = mkTab("t1", "/w/php-agent", [meta("s1", "会话A")]);
    const t2 = mkTab("t2", "/w/qianyouyou", [meta("s2", "会话B")]);
    render(<Sidebar {...base} tabs={[t1, t2]} />);
    fireEvent.change(screen.getByPlaceholderText("搜索会话"), { target: { value: "B" } });
    expect(screen.queryByText("会话A")).not.toBeInTheDocument();
    expect(screen.getByText("会话B")).toBeInTheDocument();
  });

  it("折叠箭头只收起该组，其他组不受影响", () => {
    const t1 = mkTab("t1", "/w/php-agent", [meta("s1", "会话A")]);
    const t2 = mkTab("t2", "/w/qianyouyou", [meta("s2", "会话B")]);
    render(<Sidebar {...base} tabs={[t1, t2]} />);
    fireEvent.click(screen.getByLabelText("折叠项目 php-agent"));
    expect(screen.queryByText("会话A")).not.toBeInTheDocument();
    expect(screen.getByText("会话B")).toBeInTheDocument();
  });

  it("未打开的已添加项目灰显，点击走 onOpenProject；booting 时显示 启动中…", () => {
    const t1 = mkTab("t1", "/w/php-agent", []);
    render(<Sidebar {...base} tabs={[t1]} projects={["/w/qianyouyou"]} />);
    fireEvent.click(screen.getByRole("button", { name: /qianyouyou/ }));
    expect(base.onOpenProject).toHaveBeenCalledWith("/w/qianyouyou");
    render(<Sidebar {...base} tabs={[t1]} projects={["/w/qianyouyou"]} booting="/w/qianyouyou" />);
    expect(screen.getByText("启动中…")).toBeInTheDocument();
  });

  it("组头 ✕ → onCloseProject(tab id)", () => {
    const t1 = mkTab("t1", "/w/php-agent", []);
    render(<Sidebar {...base} tabs={[t1]} />);
    fireEvent.click(screen.getByLabelText("关闭项目 php-agent"));
    expect(base.onCloseProject).toHaveBeenCalledWith("t1");
  });

  it("新建会话按钮 → onNewSession；⚙ → settingsOpen", () => {
    const t1 = mkTab("t1", "/w/php-agent", []);
    render(<Sidebar {...base} tabs={[t1]} />);
    fireEvent.click(screen.getByRole("button", { name: "新建会话" }));
    expect(base.onNewSession).toHaveBeenCalled();
    fireEvent.click(screen.getByLabelText("打开设置"));
    expect(useUiStore.getState().settingsOpen).toBe(true);
  });
});
