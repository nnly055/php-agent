import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { useAgentStream } from "./useAgentStream";
import { useAppStore } from "../store/useAppStore";
import * as api from "../lib/api";

vi.mock("../lib/api", () => ({
  confirm: vi.fn(),
  agentStream: vi.fn(),
  listSessions: vi.fn(),
}));

describe("useAgentStream.answerConfirm", () => {
  beforeEach(() => { useAppStore.getState().reset(); vi.clearAllMocks(); });

  it("restores pendingConfirm when POST fails", async () => {
    (api.confirm as any).mockRejectedValueOnce(new Error("network"));
    useAppStore.getState().setPendingConfirm({ id: "cf1", tool: "write_file", input: {}, preview: "" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.answerConfirm("y"); });
    expect(useAppStore.getState().pendingConfirm).toMatchObject({ id: "cf1" });
    expect(useAppStore.getState().error).toBe("network");
  });

  it("clears pendingConfirm on success", async () => {
    (api.confirm as any).mockResolvedValueOnce({ ok: true });
    useAppStore.getState().setPendingConfirm({ id: "cf2", tool: "run_command", input: {}, preview: "" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.answerConfirm("n"); });
    expect(useAppStore.getState().pendingConfirm).toBeNull();
    // baseUrl 参数化后：第一个参数为 backendUrl（默认单例为 ""）
    expect(api.confirm).toHaveBeenCalledWith(expect.any(String), "cf2", "n");
  });

  it("refreshes the session list after a turn completes", async () => {
    // mock agentStream as an empty async generator (turn ends immediately)
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    (api.listSessions as any).mockResolvedValueOnce({ sessions: [{ id: "s-new", title: "演示", createdAt: 0, updatedAt: 0, messageCount: 2 }] });
    useAppStore.getState().setActiveSession("s-new", []);
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("演示"); });
    expect(api.listSessions).toHaveBeenCalled();
    expect(useAppStore.getState().sessions.map(s => s.id)).toContain("s-new");
    expect(useAppStore.getState().streaming).toBe(false);
  });

  it("passes the store selection to agentStream as the 5th arg", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    useAppStore.getState().setActiveSession("s-sel", []);
    useAppStore.getState().setSelection({ profileId: "p-9", model: "m-9" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("带选择发送"); });
    expect(api.agentStream).toHaveBeenCalledWith(
      expect.any(String), "带选择发送", "s-sel", expect.any(AbortSignal), { profileId: "p-9", model: "m-9" },
    );
  });

  it("selection 默认 { null, null } 也原样透传（wire 由 agentStream 过滤空键）", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    useAppStore.getState().setActiveSession("s-def", []);
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("默认发送"); });
    expect(api.agentStream).toHaveBeenLastCalledWith(
      expect.any(String), "默认发送", "s-def", expect.any(AbortSignal), { profileId: null, model: null },
    );
  });

  it("新会话发送后 selection 保持用户所选（重置不吞掉发送前选择）", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    // 不预设 activeSession：走 send() 内的"新会话"分支（setActiveSession 会重置 selection）
    useAppStore.getState().setSelection({ profileId: "p-2", model: "glm-4.7" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("新会话首条"); });
    // 消息 1 携带用户所选
    expect(api.agentStream).toHaveBeenLastCalledWith(
      expect.any(String), "新会话首条", expect.any(String), expect.any(AbortSignal), { profileId: "p-2", model: "glm-4.7" },
    );
    // send 完成后 store 仍保持所选（composer 不跳回默认档）
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-2", model: "glm-4.7" });
  });

  it("新会话未选时保持重置态（继承默认档）", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("未选发送"); });
    expect(useAppStore.getState().selection).toEqual({ profileId: null, model: null });
    expect(api.agentStream).toHaveBeenLastCalledWith(
      expect.any(String), "未选发送", expect.any(String), expect.any(AbortSignal), { profileId: null, model: null },
    );
  });
});