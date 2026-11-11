import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { useAgentStream } from "./useAgentStream";
import { useAppStore } from "../store/useAppStore";
import * as api from "../lib/api";

vi.mock("../lib/api", () => ({
  confirm: vi.fn(),
  agentStream: vi.fn(),
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
    expect(api.confirm).toHaveBeenCalledWith("cf2", "n");
  });
});