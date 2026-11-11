import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { ConnectionBanner } from "./ConnectionBanner";
import { useAppStore } from "../store/useAppStore";
import * as tauri from "../lib/tauri";

vi.mock("../lib/tauri", () => ({
  restartSession: vi.fn(),
  getWorkspace: vi.fn().mockResolvedValue("/tmp/project"),
}));

describe("ConnectionBanner", () => {
  beforeEach(() => {
    useAppStore.getState().reset();
    useAppStore.getState().setSessionId("p-1");
    vi.clearAllMocks();
  });

  it("hidden when connected and no error", () => {
    useAppStore.getState().setConnected(true);
    const { container } = render(<ConnectionBanner onRetry={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows disconnect banner and reconnects", async () => {
    useAppStore.getState().setConnected(false);
    (tauri.restartSession as any).mockResolvedValueOnce(undefined);
    const onRetry = vi.fn();
    render(<ConnectionBanner onRetry={onRetry} />);
    expect(screen.getByText(/后端已断开/)).toBeInTheDocument();
    fireEvent.click(screen.getByText("重连"));
    await waitFor(() => expect(tauri.restartSession).toHaveBeenCalledWith("p-1"));
    expect(onRetry).toHaveBeenCalled();
  });

  it("surfaces reconnect failure in the store", async () => {
    useAppStore.getState().setConnected(false);
    (tauri.restartSession as any).mockRejectedValueOnce(new Error("spawn failed"));
    render(<ConnectionBanner onRetry={() => {}} />);
    fireEvent.click(screen.getByText("重连"));
    await waitFor(() => expect(useAppStore.getState().error).toBe("spawn failed"));
    // connected=false 时横幅仍显示断开文案（error 通过 StatusBar/后续 UI 呈现）
    expect(screen.getByText(/后端已断开/)).toBeInTheDocument();
  });
});
