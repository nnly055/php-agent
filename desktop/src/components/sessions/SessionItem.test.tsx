import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { SessionItem } from "./SessionItem";
import { useAppStore } from "../../store/useAppStore";
import { loadSession, deleteSession, listSessions } from "../../lib/api";

vi.mock("../../lib/api", () => ({
  loadSession: vi.fn(),
  deleteSession: vi.fn(),
  listSessions: vi.fn(),
}));

const meta = { id: "s1", title: "测试会话", createdAt: 0, updatedAt: 0, messageCount: 2 };

describe("SessionItem", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAppStore.getState().reset();
    vi.mocked(loadSession).mockResolvedValue({ id: "s1", messages: [] });
    vi.mocked(deleteSession).mockResolvedValue({ ok: true });
    vi.mocked(listSessions).mockResolvedValue({ sessions: [] });
  });

  it("delete requires confirmation before calling the backend", async () => {
    render(<SessionItem meta={meta} />);
    const trigger = screen.getByRole("button", { name: "会话操作" });
    // Radix trigger 在 pointerdown 打开菜单，click 仅补全真实交互序列
    fireEvent.pointerDown(trigger, { button: 0 });
    fireEvent.click(trigger);
    fireEvent.click(await screen.findByText("删除会话"));
    // 未确认前不得发起删除
    expect(deleteSession).not.toHaveBeenCalled();
    expect(await screen.findByText(/确定删除/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "删除" }));
    // baseUrl 参数化后：第一个参数为项目 store 的 backendUrl（默认单例为 ""）
    await waitFor(() => expect(deleteSession).toHaveBeenCalledWith(expect.any(String), "s1"));
  });

  it("open surfaces error in store when loadSession fails", async () => {
    vi.mocked(loadSession).mockRejectedValue(new Error("HTTP 500"));
    render(<SessionItem meta={meta} />);
    fireEvent.click(screen.getByRole("button", { name: /测试会话/ }));
    await waitFor(() => expect(useAppStore.getState().error).toBe("HTTP 500"));
  });

  it("open restores persisted selection carried by the session meta", async () => {
    // meta 来自 /sessions 列表项（props），携带落库时的平台/模型选择
    render(<SessionItem meta={{ ...meta, profileId: "p-7", model: "m-7" }} />);
    fireEvent.click(screen.getByRole("button", { name: /测试会话/ }));
    await waitFor(() => expect(useAppStore.getState().activeSession).toBe("s1"));
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-7", model: "m-7" });
  });

  it("open without meta selection leaves selection reset (no stale carry-over)", async () => {
    useAppStore.getState().setSelection({ profileId: "p-old", model: "m-old" });
    render(<SessionItem meta={meta} />);
    fireEvent.click(screen.getByRole("button", { name: /测试会话/ }));
    await waitFor(() => expect(useAppStore.getState().activeSession).toBe("s1"));
    // setActiveSession 重置选择，meta 未携带时不得回填旧值
    expect(useAppStore.getState().selection).toEqual({ profileId: null, model: null });
  });

  it("open tolerates malformed history without empty bubbles", async () => {
    vi.mocked(loadSession).mockResolvedValue({
      id: "s1",
      messages: [
        { role: "user", content: [{ type: "text", text: "你好" }, null] },
        { role: "assistant", content: [] }, // 无文本无工具 → 跳过
        { role: "assistant", content: null }, // 非数组 → 跳过
        { role: "assistant", content: [null, { type: "text", text: "答" }, { type: "tool_use", id: "t1", name: "read_file", input: {} }] },
        { role: "assistant", content: [{ type: "tool_use", id: "t2", name: "bash", input: {} }] }, // 仅工具 → 保留
        null,
      ] as any[],
    });
    render(<SessionItem meta={meta} />);
    fireEvent.click(screen.getByRole("button", { name: /测试会话/ }));
    await waitFor(() => expect(useAppStore.getState().activeSession).toBe("s1"));
    const msgs = useAppStore.getState().messages;
    expect(msgs).toHaveLength(3);
    expect(msgs[0]).toMatchObject({ role: "user", text: "你好" });
    expect(msgs[1]).toMatchObject({ role: "assistant", text: "答" });
    expect(msgs[1].tools).toHaveLength(1);
    expect(msgs[2].text).toBe("");
    expect(msgs[2].tools).toHaveLength(1);
    expect(msgs.every((m) => m.text || m.tools.length)).toBe(true);
  });
});