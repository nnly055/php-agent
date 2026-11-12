import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { Composer } from "./Composer";
import { useAppStore } from "../../store/useAppStore";
import { useUiStore } from "../../store/useUiStore";

// Task 14 起后端以对象序列化模型（ModelEntry）：mock 同步为 { name } 形式
const payload = {
  profiles: [
    { id: "p-1", name: "DS", provider: "openai", apiKey: "sk-a", baseUrl: "", models: [{ name: "deepseek-chat" }, { name: "deepseek-reasoner" }] },
    { id: "p-2", name: "GLM", provider: "openai", apiKey: "sk-b", baseUrl: "", models: [{ name: "glm-4.7" }] },
  ],
  defaultProfile: "p-1",
  defaultModel: "deepseek-chat",
};

describe("Composer IME guard", () => {
  it("does not send on Enter while IME composing", () => {
    useAppStore.getState().reset();
    const onSend = vi.fn();
    render(<Composer onSend={onSend} onAbort={() => {}} />);
    const ta = screen.getByPlaceholderText(/输入任务/);
    fireEvent.change(ta, { target: { value: "nihao" } });
    fireEvent.keyDown(ta, { key: "Enter", isComposing: true });
    expect(onSend).not.toHaveBeenCalled();
  });
  it("sends on plain Enter", () => {
    useAppStore.getState().reset();
    const onSend = vi.fn();
    render(<Composer onSend={onSend} onAbort={() => {}} />);
    const ta = screen.getByPlaceholderText(/输入任务/);
    fireEvent.change(ta, { target: { value: "hello" } });
    fireEvent.keyDown(ta, { key: "Enter" });
    expect(onSend).toHaveBeenCalledWith("hello");
  });
});

describe("Composer 平台/模型切换器", () => {
  beforeEach(() => {
    useUiStore.getState().setProfilesPayload(null);
    useAppStore.getState().reset();
  });

  it("渲染平台/模型选择器，改变即写入 store", () => {
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    // 默认平台 = 全局默认档 p-1；模型下拉 = 该平台的模型列表
    const selects = screen.getAllByRole("combobox");
    expect(selects).toHaveLength(2);
    expect(selects[0]).toHaveValue("p-1");
    expect(screen.getByRole("option", { name: "deepseek-chat" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "deepseek-reasoner" })).toBeInTheDocument();
    // 切平台 → selection 更新且模型回落新平台首个
    fireEvent.change(selects[0], { target: { value: "p-2" } });
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-2", model: "glm-4.7" });
    // 模型下拉随平台切换：现在是 glm-4.7，p-1 的模型不再出现
    expect(screen.getByRole("option", { name: "glm-4.7" })).toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "deepseek-chat" })).not.toBeInTheDocument();
    // 切回 p-1 再选 reasoner，验证 model 通道
    fireEvent.change(selects[0], { target: { value: "p-1" } });
    fireEvent.change(selects[1], { target: { value: "deepseek-reasoner" } });
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-1", model: "deepseek-reasoner" });
  });

  it("目录为空数组时不渲染选择器", () => {
    useUiStore.getState().setProfilesPayload({ profiles: [], defaultProfile: "", defaultModel: "" });
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.queryAllByRole("combobox")).toHaveLength(0);
  });

  it("目录为 null 时也不渲染选择器", () => {
    useUiStore.getState().setProfilesPayload(null);
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.queryAllByRole("combobox")).toHaveLength(0);
  });

  it("当前模型不在列表时插入临时 option，select 不渲染为空白", () => {
    // 模拟恢复会话携带的自定义模型（目录里已不存在该模型名）
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    useAppStore.getState().setSelection({ profileId: "p-1", model: "custom-x" });
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.getAllByRole("combobox")[1]).toHaveValue("custom-x");
    expect(screen.getByRole("option", { name: "custom-x" })).toBeInTheDocument();
  });

  it("selection 为空时回落所选平台首个模型，不使用全局 defaultModel（显示=实际）", () => {
    // defaultModel ≠ 平台首个模型：服务端从不使用 defaultModel，显示它就是谎报
    useUiStore.getState().setProfilesPayload({ ...payload, defaultModel: "phantom-model" });
    useAppStore.getState().setActiveSession("web-1", []);
    render(<Composer onSend={vi.fn()} onAbort={vi.fn()} />);
    expect(screen.getAllByRole("combobox")[1]).toHaveValue("deepseek-chat");
    expect(screen.queryByRole("option", { name: "phantom-model" })).not.toBeInTheDocument();
  });
});