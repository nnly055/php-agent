import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { ConnectionBanner } from "./ConnectionBanner";
import { useAppStore } from "../store/useAppStore";
import * as tauri from "../lib/tauri";

vi.mock("../lib/tauri", () => ({ restartBackend: vi.fn() }));

describe("ConnectionBanner", () => {
  beforeEach(() => { useAppStore.getState().reset(); vi.clearAllMocks(); });

  it("hidden when connected and no error", () => {
    useAppStore.getState().setConnected(true);
    const { container } = render(<ConnectionBanner onRetry={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows disconnect banner and reconnects", async () => {
    useAppStore.getState().setConnected(false);
    (tauri.restartBackend as any).mockResolvedValueOnce(undefined);
    const onRetry = vi.fn();
    render(<ConnectionBanner onRetry={onRetry} />);
    expect(screen.getByText(/后端已断开/)).toBeInTheDocument();
    fireEvent.click(screen.getByText("重连"));
    await waitFor(() => expect(tauri.restartBackend).toHaveBeenCalled());
    expect(onRetry).toHaveBeenCalled();
  });
});