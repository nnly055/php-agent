import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor, act } from "@testing-library/react";
import { SettingsDialog } from "./SettingsDialog";
import { useUiStore } from "../../store/useUiStore";
import { useAppStore } from "../../store/useAppStore";
import * as tauri from "../../lib/tauri";
import * as api from "../../lib/api";

vi.mock("../../lib/tauri", () => ({
  getProfiles: vi.fn().mockResolvedValue({
    profiles: [{ id: "p-1", name: "DS", provider: "openai", apiKey: "sk-old", baseUrl: "https://api.deepseek.com", models: [{ name: "deepseek-chat" }] }],
    defaultProfile: "p-1", defaultModel: "deepseek-chat",
  }),
  saveProfiles: vi.fn().mockResolvedValue(undefined),
  // 仅为断言「保存不重启」而 mock：对话框流程不得触碰 restartSession
  restartSession: vi.fn(),
  // MCP Tab：全局含合法 fs 与非法 both（command/url 皆有，须能回填显示不崩），项目含 http 条目
  getMcp: vi.fn().mockResolvedValue({
    global: [
      { name: "fs", command: "npx", args: ["-y"], env: { K: "v" }, url: "", headers: {}, timeout: 5 },
      { name: "both", command: "x", args: [], env: {}, url: "https://a", headers: {}, timeout: null },
    ],
    project: [{ name: "remote", command: "", args: [], env: {}, url: "https://mcp.example.com", headers: { Authorization: "Bearer x" }, timeout: null }],
  }),
  saveMcpGlobal: vi.fn().mockResolvedValue(undefined),
  saveMcpProject: vi.fn().mockResolvedValue(undefined),
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

  it("回填目录并渲染平台列表（模型条目行）", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await waitFor(() => expect(screen.getByDisplayValue("DS")).toBeInTheDocument());
    // 模型条目行：名称输入框回填该平台的首个模型（对象形式的 name 字段）
    expect(screen.getByPlaceholderText(/模型名，如/)).toHaveDisplayValue("deepseek-chat");
    // contextTokens / maxTokens 数值输入框存在且为空（mock 未提供）；maxTokens 下限在 placeholder 中明示
    expect(screen.getByPlaceholderText(/上下文/)).toHaveDisplayValue("");
    expect(screen.getByPlaceholderText(/输出 ≥1024/)).toHaveDisplayValue("");
    // 全局 defaultModel 旋钮已移除（服务端从不使用；输入框不再渲染）
    expect(screen.queryByRole("textbox", { name: /默认模型/ })).not.toBeInTheDocument();
    // 单平台：默认 radio 勾选，删除按钮禁用（至少保留一个平台）
    expect(screen.getByRole("radio")).toBeChecked();
    expect(screen.getByRole("button", { name: "删除" })).toBeDisabled();
  });

  it("编辑模型条目名称并保存：saveProfiles + pushProfiles，无 restart", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await screen.findByDisplayValue("DS");
    const nameInput = screen.getByPlaceholderText(/模型名，如/);
    fireEvent.change(nameInput, { target: { value: "deepseek-reasoner" } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveProfiles).toHaveBeenCalled());
    const arg = (tauri.saveProfiles as any).mock.calls[0][0];
    expect(arg.profiles[0].models).toEqual([{ name: "deepseek-reasoner" }]);
    // defaultModel 恒传空串：全局旋钮已移除，模型回落 profile 首个
    expect(arg.defaultModel).toBe("");
    expect(api.pushProfiles).toHaveBeenCalledWith("http://127.0.0.1:1", expect.objectContaining({ defaultProfile: "p-1" }));
    expect((tauri as any).restartSession).not.toHaveBeenCalled();
  });

  it("添加模型：条目行数 +1，新行为空名（保存时被过滤）", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await screen.findByDisplayValue("DS");
    expect(screen.getAllByPlaceholderText(/模型名，如/)).toHaveLength(1);
    fireEvent.click(screen.getByText("添加模型"));
    await waitFor(() => expect(screen.getAllByPlaceholderText(/模型名，如/)).toHaveLength(2));
    // 新行：名称/上下文/输出均为空
    const nameInputs = screen.getAllByPlaceholderText(/模型名，如/);
    expect(nameInputs[1]).toHaveDisplayValue("");
    expect(screen.getAllByPlaceholderText(/上下文/)[1]).toHaveDisplayValue("");
    expect(screen.getAllByPlaceholderText(/输出 ≥1024/)[1]).toHaveDisplayValue("");
    // 空名行在保存组装时被过滤，payload 只含原条目
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveProfiles).toHaveBeenCalled());
    const arg = (tauri.saveProfiles as any).mock.calls[0][0];
    expect(arg.profiles[0].models).toHaveLength(1);
  });

  it("删除模型：移除对应条目行", async () => {
    useUiStore.getState().setProfilesPayload({
      profiles: [{ id: "p-1", name: "DS", provider: "openai", apiKey: "sk-old", baseUrl: "", models: [{ name: "m-1" }, { name: "m-2" }] }],
      defaultProfile: "p-1", defaultModel: "",
    });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} />);
    await waitFor(() => expect(screen.getAllByPlaceholderText(/模型名，如/)).toHaveLength(2));
    expect(screen.getAllByPlaceholderText(/模型名，如/)[0]).toHaveDisplayValue("m-1");
    // 删除第一行（m-1），剩余 m-2
    fireEvent.click(screen.getAllByRole("button", { name: "删除模型" })[0]);
    await waitFor(() => expect(screen.getAllByPlaceholderText(/模型名，如/)).toHaveLength(1));
    expect(screen.getByPlaceholderText(/模型名，如/)).toHaveDisplayValue("m-2");
  });

  it("contextTokens 往返：填 128000 保存后为 128000，清空保存后为 null", async () => {
    // 推送失败让对话框保持打开（既有行为），两次保存都在同一编辑态内完成
    (api.pushProfiles as any).mockRejectedValue("push fail");
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await screen.findByDisplayValue("DS");
    const ctxInput = screen.getByPlaceholderText(/上下文/);
    fireEvent.change(ctxInput, { target: { value: "128000" } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveProfiles).toHaveBeenCalled());
    let arg = (tauri.saveProfiles as any).mock.calls[0][0];
    expect(arg.profiles[0].models[0].contextTokens).toBe(128000);

    // 清空 → null（未设置，由后端回落默认）
    fireEvent.change(screen.getByPlaceholderText(/上下文/), { target: { value: "" } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect((tauri.saveProfiles as any).mock.calls.length).toBeGreaterThanOrEqual(2));
    arg = (tauri.saveProfiles as any).mock.calls.at(-1)[0];
    expect(arg.profiles[0].models[0].contextTokens).toBeNull();
  });

  it("maxTokens=500 保存被前置校验拦截：saveProfiles/pushProfiles 均不调用，行内提示 1024", async () => {
    // 与 PHP setProfiles 的 ≥1024 下限对齐：防止 Rust 保存成功而 PHP 推送被拒的 split-brain
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await screen.findByDisplayValue("DS");
    fireEvent.change(screen.getByPlaceholderText(/输出 ≥1024/), { target: { value: "500" } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(screen.getByText(/1024/)).toBeInTheDocument());
    expect(tauri.saveProfiles).not.toHaveBeenCalled();
    expect(api.pushProfiles).not.toHaveBeenCalled();
  });

  it("maxTokens/contextTokens 输入归一化：小数截断为整数、非法输入置空；1024 下限值可保存", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl="http://127.0.0.1:1" />);
    await screen.findByDisplayValue("DS");
    const maxInput = screen.getByPlaceholderText(/输出 ≥1024/);
    fireEvent.change(maxInput, { target: { value: "3.5" } });
    expect(maxInput).toHaveDisplayValue("3");
    fireEvent.change(maxInput, { target: { value: "abc" } });
    expect(maxInput).toHaveDisplayValue("");
    const ctxInput = screen.getByPlaceholderText(/上下文/);
    fireEvent.change(ctxInput, { target: { value: "9.9" } });
    expect(ctxInput).toHaveDisplayValue("9");
    // 恰在下限的 1024 可正常走保存链路
    fireEvent.change(maxInput, { target: { value: "1024" } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveProfiles).toHaveBeenCalled());
    const arg = (tauri.saveProfiles as any).mock.calls[0][0];
    expect(arg.profiles[0].models[0].maxTokens).toBe(1024);
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
    expect(api.testConfig).toHaveBeenCalledWith(
      "http://127.0.0.1:1",
      expect.objectContaining({ apiKey: "sk-old", model: "deepseek-chat" }),
    );
  });

  it("默认档指向第二个平台时，测试连接用第二个平台的值", async () => {
    (api.testConfig as any).mockResolvedValue({ ok: true, message: "连接成功", latency_ms: 66 });
    useUiStore.getState().setProfilesPayload({
      profiles: [
        { id: "p-1", name: "DS", provider: "openai", apiKey: "sk-first", baseUrl: "https://first.example", models: [{ name: "m1" }] },
        { id: "p-2", name: "GLM", provider: "openai", apiKey: "sk-second", baseUrl: "https://second.example", models: [{ name: "m2" }] },
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
      profiles: [{ id: "cached", name: "缓存平台", provider: "anthropic", apiKey: "sk-cache", baseUrl: "https://cached.example", models: [{ name: "m-cached" }] }],
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

describe("SettingsDialog MCP 配置", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useUiStore.getState().setSettingsOpen(false);
    useUiStore.getState().setProfilesPayload(null);
    useAppStore.getState().reset();
  });

  it("Tab 切换显示 MCP 配置并回填全局/项目条目", async () => {
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} activeWorkspace="/tmp/ws" />);
    fireEvent.click(screen.getByText("MCP"));
    // 名称输入按 DOM 序：全局 fs、both，项目 remote
    await waitFor(() => expect(screen.getAllByPlaceholderText(/server 名称/)).toHaveLength(3));
    const names = screen.getAllByPlaceholderText(/server 名称/).map((el) => (el as HTMLInputElement).value);
    expect(names).toEqual(["fs", "both", "remote"]);
    expect(tauri.getMcp).toHaveBeenCalledWith("/tmp/ws");
    // fs 卡片：args/env/timeout 回填
    expect(screen.getAllByPlaceholderText(/每行一个参数/)[0]).toHaveDisplayValue("-y");
    expect(screen.getAllByPlaceholderText(/KEY=VALUE/)[0]).toHaveDisplayValue("K=v");
    expect(screen.getAllByPlaceholderText(/30（默认）/)[0]).toHaveDisplayValue("5");
    // both（文件里的非法条目）：command 与 url 同时回填显示，kind 取 stdio（command 非空）
    expect(screen.getAllByPlaceholderText(/命令，如/)[1]).toHaveDisplayValue("x");
    expect(screen.queryByDisplayValue("https://a")).not.toBeInTheDocument();
    // remote 卡片：headers 回填；timeout null 显示空
    expect(screen.getAllByPlaceholderText(/Authorization: Bearer/)[0]).toHaveDisplayValue("Authorization: Bearer x");
    expect(screen.getAllByPlaceholderText(/30（默认）/)[1]).toHaveDisplayValue("");
  });

  it("MCP 保存：解析文本字段并调用 saveMcpGlobal + saveMcpProject", async () => {
    // 保存用合法条目（both 会被校验拦截），覆盖默认 mock
    (tauri.getMcp as any).mockResolvedValue({
      global: [{ name: "fs", command: "npx", args: ["-y"], env: { K: "v" }, url: "", headers: {}, timeout: 5 }],
      project: [{ name: "remote", command: "", args: [], env: {}, url: "https://mcp.example.com", headers: { Authorization: "Bearer x" }, timeout: null }],
    });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} activeWorkspace="/tmp/ws" />);
    fireEvent.click(screen.getByText("MCP"));
    await waitFor(() => expect(screen.getAllByPlaceholderText(/server 名称/)).toHaveLength(2));
    // 全局区新增条目：名称 g2、类型 http、url、headers（badline 无分隔符丢弃；B= 空值保留）
    // 新卡插在 fs 之后：DOM 序 name 输入 = fs、新卡、remote → 新卡是下标 1
    fireEvent.click(screen.getAllByText("添加 server")[0]);
    expect(screen.getAllByPlaceholderText(/server 名称/)).toHaveLength(3);
    fireEvent.change(screen.getAllByPlaceholderText(/server 名称/)[1], { target: { value: "g2" } });
    // kind select：MCP tab 内 combobox 按 DOM 序 = 全局 fs、全局新卡、项目 remote；新卡（第 2 个）切 http
    const kindSelects = screen.getAllByRole("combobox");
    expect(kindSelects).toHaveLength(3);
    fireEvent.change(kindSelects[1], { target: { value: "http" } });
    fireEvent.change(screen.getAllByPlaceholderText("https://mcp.example.com/mcp")[0], { target: { value: "https://x" } });
    // headersText：无 ":" 的行丢弃；"B:"（空值）保留；"=" 解析路径由 fs 条目的 env 覆盖
    fireEvent.change(screen.getAllByPlaceholderText(/Authorization: Bearer/)[0], { target: { value: "A: 1\nbadline\nB: " } });
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveMcpGlobal).toHaveBeenCalled());
    const g = (tauri.saveMcpGlobal as any).mock.calls[0][0];
    expect(g).toEqual([
      { name: "fs", command: "npx", args: ["-y"], env: { K: "v" }, url: "", headers: {}, timeout: 5 },
      { name: "g2", command: "", args: [], env: {}, url: "https://x", headers: { A: "1", B: "" }, timeout: null },
    ]);
    expect(tauri.saveMcpProject).toHaveBeenCalledWith("/tmp/ws", [
      { name: "remote", command: "", args: [], env: {}, url: "https://mcp.example.com", headers: { Authorization: "Bearer x" }, timeout: null },
    ]);
    await waitFor(() => expect(screen.getByText(/已保存/)).toBeInTheDocument());
  });

  it("无打开项目：项目区置灰提示，保存仅调用 saveMcpGlobal", async () => {
    (tauri.getMcp as any).mockResolvedValue({
      global: [{ name: "fs", command: "npx", args: [], env: {}, url: "", headers: {}, timeout: null }],
      project: null,
    });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} activeWorkspace={null} />);
    fireEvent.click(screen.getByText("MCP"));
    expect(tauri.getMcp).toHaveBeenCalledWith(null);
    await waitFor(() => expect(screen.getByPlaceholderText(/server 名称/)).toBeInTheDocument());
    expect(screen.getByText(/未打开项目/)).toBeInTheDocument();
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(tauri.saveMcpGlobal).toHaveBeenCalled());
    expect(tauri.saveMcpProject).not.toHaveBeenCalled();
  });

  it("校验拦截：command/url 皆空的非法条目保存报错（与 PHP 语义对齐）", async () => {
    (tauri.getMcp as any).mockResolvedValue({
      global: [{ name: "bad", command: "", args: [], env: {}, url: "", headers: {}, timeout: null }],
      project: null,
    });
    useUiStore.getState().setSettingsOpen(true);
    render(<SettingsDialog testUrl={null} activeWorkspace="/tmp/ws" />);
    fireEvent.click(screen.getByText("MCP"));
    await waitFor(() => expect(screen.getByPlaceholderText(/server 名称/)).toBeInTheDocument());
    fireEvent.click(screen.getByText("保存"));
    await waitFor(() => expect(screen.getByText(/二选一/)).toBeInTheDocument());
    expect(tauri.saveMcpGlobal).not.toHaveBeenCalled();
    expect(tauri.saveMcpProject).not.toHaveBeenCalled();
  });
});
