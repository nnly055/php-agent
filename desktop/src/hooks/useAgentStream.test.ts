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
});