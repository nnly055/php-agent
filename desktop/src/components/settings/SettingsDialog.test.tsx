import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { SettingsDialog } from "./SettingsDialog";
import { useUiStore } from "../../store/useUiStore";
// brief 原文含 `import * as tauri` 但从未引用（vi.mock 工厂即可拦截），
// noUnusedLocals 下无法通过 tsc，故移除该未使用导入。
import * as api from "../../lib/api";

vi.mock("../../lib/tauri", () => ({
  getConfig: vi.fn().mockResolvedValue({ provider: "openai", apiKey: "sk-old", baseUrl: "https://x/v1", model: "gpt-4o" }),
  saveConfig: vi.fn().mockResolvedValue(undefined),
}));
vi.mock("../../lib/api", () => ({ testConfig: vi.fn() }));

describe("SettingsDialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useUiStore.getState().setSettingsOpen(false);
  });

  it("open 时回填已保存配置", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={vi.fn()} />);
    await waitFor(() => expect(screen.getByDisplayValue("sk-old")).toBeInTheDocument());
    expect(screen.getByDisplayValue("gpt-4o")).toBeInTheDocument();
  });

  it("测试连接成功显示延迟", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: true, message: "连接成功", latency_ms: 320 });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={vi.fn()} />);
    fireEvent.click(await screen.findByText("测试连接"));
    await waitFor(() => expect(screen.getByText(/连接成功（320ms）/)).toBeInTheDocument());
  });

  it("测试连接失败显示错误信息", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: false, message: "401 未授权", latency_ms: 90 });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={vi.fn()} />);
    fireEvent.click(await screen.findByText("测试连接"));
    await waitFor(() => expect(screen.getByText(/401 未授权/)).toBeInTheDocument());
  });

  it("保存调用 onSaved 并带上表单值", async () => {
    const onSaved = vi.fn().mockResolvedValue(undefined);
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" onSaved={onSaved} />);
    fireEvent.click(await screen.findByText("保存"));
    await waitFor(() =>
      expect(onSaved).toHaveBeenCalledWith({ provider: "openai", apiKey: "sk-old", baseUrl: "https://x/v1", model: "gpt-4o" }),
    );
  });

  // 与 brief 的差异：改用 await findByText 在 act 内冲掉 mount 时 getConfig 的异步回填，
  // 避免 jsdom 下 act(...) 警告噪音（断言语义不变：按钮仍为禁用）。
  it("testUrl 为 null 时测试按钮禁用", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} onSaved={vi.fn()} />);
    expect(await screen.findByText("测试连接")).toBeDisabled();
  });
});
