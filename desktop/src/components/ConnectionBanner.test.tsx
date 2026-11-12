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
    useAppStore.getState().setBackend("http://127.0.0.1:1111");
    vi.clearAllMocks();
  });

  it("hidden when connected and no error", () => {
    useAppStore.getState().setConnected(true);
    const { container } = render(<ConnectionBanner onRetry={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows disconnect banner and reconnects", async () => {
    useAppStore.getState().setConnected(false);
    (tauri.restartSession as any).mockResolvedValueOnce({ sessionId: "p-2", port: 1235, workspace: "/tmp/project" });
    const onRetry = vi.fn();
    render(<ConnectionBanner onRetry={onRetry} />);
    expect(screen.getByText(/后端已断开/)).toBeInTheDocument();
    fireEvent.click(screen.getByText("重连"));
    await waitFor(() => expect(tauri.restartSession).toHaveBeenCalledWith("p-1"));
    // 重启产生新 sessionId/端口：store 必须重绑，否则后续请求打到旧地址
    await waitFor(() => {
      expect(useAppStore.getState().sessionId).toBe("p-2");
      expect(useAppStore.getState().backendUrl).toBe("http://127.0.0.1:1235");
    });
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
