import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor, act } from "@testing-library/react";
import { SettingsDialog } from "./SettingsDialog";
import { useUiStore } from "../../store/useUiStore";
import { useAppStore } from "../../store/useAppStore";
import * as tauri from "../../lib/tauri";
import * as api from "../../lib/api";

vi.mock("../../lib/tauri", () => ({
  getProfiles: vi.fn().mockResolvedValue({
    profiles: [{ id: "p-1", name: "DS", provider: "openai", apiKey: "sk-old", baseUrl: "https://api.deepseek.com", models: ["deepseek-chat"] }],
    defaultProfile: "p-1", defaultModel: "deepseek-chat",
  }),
  saveProfiles: vi.fn().mockResolvedValue(undefined),
  // 仅为断言「保存不重启」而 mock：对话框流程不得触碰 restartSession
  restartSession: vi.fn(),
}));
vi.mock("../../lib/api", () => ({ testConfig: vi.fn(), pushProfiles: vi.fn().mockResolvedValue({ ok: true }) }));

// 与 brief 的差异（允许的微调）：新添加平台条目的 baseUrl 为空字符串，
// 「getAllByDisplayValue(/https?:\/\//) ≥ 2」不可能成立，改为断言名称输入框与默认 radio 数量。

describe("SettingsDialog 平台管理", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useUiStore.getState().setSettingsOpen(false);
    useUiStore.getState().setProfilesPayload(null);
    useAppStore.getState().reset();
  });

  it("回填目录并渲染平台列表", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await waitFor(() => expect(screen.getByDisplayValue("DS")).toBeInTheDocument());
    // 模型列表 textarea 回填该平台的模型
    expect(screen.getByRole("textbox", { name: /一行一个/ })).toHaveDisplayValue("deepseek-chat");
    // 全局 defaultModel 旋钮已移除（服务端从不使用；输入框不再渲染）
    expect(screen.queryByRole("textbox", { name: /默认模型/ })).not.toBeInTheDocument();
    // 单平台：默认 radio 勾选，删除按钮禁用（至少保留一个平台）
    expect(screen.getByRole("radio")).toBeChecked();
    expect(screen.getByRole("button", { name: "删除" })).toBeDisabled();
  });

  it("编辑模型行并保存：saveProfiles + pushProfiles，无 restart", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await screen.findByDisplayValue("DS");
    const modelInput = screen.getByRole("textbox", { name: /一行一个/ });
    fireEvent.change(modelInput, { target: { value: "deepseek-reasoner" } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveProfiles).toHaveBeenCalled());
    const arg = (tauri.saveProfiles as any).mock.calls[0][0];
    expect(arg.profiles[0].models).toEqual(["deepseek-reasoner"]);
    // defaultModel 恒传空串：全局旋钮已移除，模型回落 profile 首个
    expect(arg.defaultModel).toBe("");
    expect(api.pushProfiles).toHaveBeenCalledWith("http://127.0.0.1:1", expect.objectContaining({ defaultProfile: "p-1" }));
    expect((tauri as any).restartSession).not.toHaveBeenCalled();
  });

  it("添加平台产生第二个条目并成为可选项", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    fireEvent.click(await screen.findByText("添加平台"));
    await waitFor(() => expect(screen.getAllByPlaceholderText(/平台名称/).length).toBe(2));
    expect(screen.getAllByRole("radio").length).toBe(2); // 新条目可设为默认
    // 两个平台时删除按钮可用
    screen.getAllByRole("button", { name: "删除" }).forEach((b) => expect(b).toBeEnabled());
  });

  it("测试连接用默认档卡片的值", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: true, message: "连接成功", latency_ms: 88 });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    const btn = await screen.findByText("测试连接");
    // 测试按钮在目录加载完成前禁用；等其可用再点击，避免空表单竞态
    await waitFor(() => expect(btn).toBeEnabled());
    fireEvent.click(btn);
    await waitFor(() => expect(screen.getByText(/连接成功（88ms）/)).toBeInTheDocument());
    expect(api.testConfig).toHaveBeenCalledWith("http://127.0.0.1:1", expect.objectContaining({ apiKey: "sk-old" }));
  });

  it("默认档指向第二个平台时，测试连接用第二个平台的值", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: true, message: "连接成功", latency_ms: 66 });
    useUiStore.getState().setProfilesPayload({
      profiles: [
        { id: "p-1", name: "DS", provider: "openai", apiKey: "sk-first", baseUrl: "https://first.example", models: ["m1"] },
        { id: "p-2", name: "GLM", provider: "openai", apiKey: "sk-second", baseUrl: "https://second.example", models: ["m2"] },
      ],
      defaultProfile: "p-2",
      defaultModel: "",
    });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    const btn = await screen.findByText("测试连接");
    await waitFor(() => expect(btn).toBeEnabled());
    fireEvent.click(btn);
    await waitFor(() => expect(screen.getByText(/连接成功（66ms）/)).toBeInTheDocument());
    expect(api.testConfig).toHaveBeenCalledWith(
      "http://127.0.0.1:1",
      expect.objectContaining({ apiKey: "sk-second", baseUrl: "https://second.example", model: "m2" }),
    );
  });

  it("无目录时显示空态引导", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} />);
    expect(screen.getByText(/尚未配置任何平台/)).toBeInTheDocument();
    // 断言后冲掉 getProfiles 兜底的异步回填，避免 teardown 时的 act 警告噪音
    await act(async () => {});
  });

  it("优先使用 profilesPayload 缓存回填，不调 getProfiles", async () => {
    useUiStore.getState().setProfilesPayload({
      profiles: [{ id: "cached", name: "缓存平台", provider: "anthropic", apiKey: "sk-cache", baseUrl: "https://cached.example", models: ["m-cached"] }],
      defaultProfile: "cached", defaultModel: "m-cached",
    });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await waitFor(() => expect(screen.getByDisplayValue("缓存平台")).toBeInTheDocument());
    expect(screen.getByDisplayValue("sk-cache")).toBeInTheDocument();
    expect(tauri.getProfiles).not.toHaveBeenCalled();
  });

  it("删除平台后仅剩一个条目，删除按钮再次禁用", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    fireEvent.click(await screen.findByText("添加平台"));
    await waitFor(() => expect(screen.getAllByPlaceholderText(/平台名称/).length).toBe(2));
    fireEvent.click(screen.getAllByRole("button", { name: "删除" })[0]);
    await waitFor(() => expect(screen.getAllByPlaceholderText(/平台名称/).length).toBe(1));
    expect(screen.getByRole("button", { name: "删除" })).toBeDisabled();
  });
});
