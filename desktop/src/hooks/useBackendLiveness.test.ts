import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { renderHook } from "@testing-library/react";
import { useBackendLiveness } from "./useBackendLiveness";
import { useAppStore } from "../store/useAppStore";
import * as api from "../lib/api";

vi.mock("../lib/api", () => ({ getStatus: vi.fn() }));

describe("useBackendLiveness", () => {
  beforeEach(() => { useAppStore.getState().reset(); vi.useFakeTimers(); vi.clearAllMocks(); });
  afterEach(() => { vi.useRealTimers(); });

  it("marks disconnected when poll fails", async () => {
    (api.getStatus as any).mockRejectedValue(new Error("down"));
    useAppStore.getState().setConnected(true);
    renderHook(() => useBackendLiveness(true, 1000));
    await vi.advanceTimersByTimeAsync(1000);
    expect(useAppStore.getState().connected).toBe(false);
  });

  it("marks connected when poll succeeds", async () => {
    (api.getStatus as any).mockResolvedValue({ provider: "fake" });
    useAppStore.getState().setConnected(false);
    renderHook(() => useBackendLiveness(true, 1000));
    await vi.advanceTimersByTimeAsync(1000);
    expect(useAppStore.getState().connected).toBe(true);
  });

  it("does not poll when disabled", async () => {
    renderHook(() => useBackendLiveness(false, 1000));
    await vi.advanceTimersByTimeAsync(3000);
    expect(api.getStatus).not.toHaveBeenCalled();
  });
});