import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { ModelPicker } from "./ModelPicker";
import { useAppStore } from "../../store/useAppStore";
import { useUiStore } from "../../store/useUiStore";

const payload = {
  profiles: [
    { id: "p-1", name: "DS", provider: "openai", apiKey: "sk-a", baseUrl: "", models: [{ name: "deepseek-chat" }, { name: "deepseek-reasoner" }] },
    { id: "p-2", name: "GLM", provider: "anthropic", apiKey: "sk-b", baseUrl: "", models: [{ name: "glm-4.7" }] },
  ],
  defaultProfile: "p-1",
  defaultModel: "deepseek-chat",
};

// Radix trigger 在 pointerdown 打开菜单，click 仅补全真实交互序列（SessionItem.test 先例）
function openPicker() {
  const trigger = screen.getByRole("button", { name: "选择模型" });
  fireEvent.pointerDown(trigger, { button: 0 });
  fireEvent.click(trigger);
}

describe("ModelPicker（Composer 模型胶囊按钮）", () => {
  beforeEach(() => {
    useUiStore.getState().setProfilesPayload(null);
    useUiStore.getState().setSettingsOpen(false);
    useAppStore.getState().reset();
  });

  it("胶囊显示当前模型名（selection 为空时回落默认档首模型）", () => {
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    render(<ModelPicker />);
    expect(screen.getByRole("button", { name: "选择模型" })).toHaveTextContent("deepseek-chat");
  });

  it("弹层按档分组渲染，当前模型带 aria-current", () => {
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    render(<ModelPicker />);
    openPicker();
    // 组头：档名 + provider 小标
    expect(screen.getByText("DS")).toBeInTheDocument();
    expect(screen.getByText("GLM")).toBeInTheDocument();
    expect(screen.getByText(/openai 兼容/)).toBeInTheDocument();
    expect(screen.getByText(/anthropic 原生/)).toBeInTheDocument();
    // 模型行：当前项 aria-current
    expect(screen.getByRole("menuitemradio", { name: "deepseek-chat" })).toHaveAttribute("aria-current", "true");
    expect(screen.getByRole("menuitemradio", { name: "glm-4.7" })).toHaveAttribute("aria-current", "false");
  });

  it("选另一档模型 → selection 三键写穿（profileId/model 变、effort 保留）", () => {
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    useAppStore.getState().setSelection({ profileId: "p-1", model: "deepseek-chat", effort: "high" });
    render(<ModelPicker />);
    openPicker();
    fireEvent.click(screen.getByRole("menuitemradio", { name: "glm-4.7" }));
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-2", model: "glm-4.7", effort: "high" });
  });

  it("思考强度五档：选 max 只改 effort，profile/model 不变", () => {
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    useAppStore.getState().setSelection({ profileId: "p-1", model: "deepseek-chat", effort: "off" });
    render(<ModelPicker />);
    openPicker();
    expect(screen.getByRole("menuitemradio", { name: "最高" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("menuitemradio", { name: "最高" }));
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-1", model: "deepseek-chat", effort: "max" });
  });

  it("管理模型… 打开设置对话框", () => {
    useUiStore.getState().setProfilesPayload(payload);
    render(<ModelPicker />);
    openPicker();
    fireEvent.click(screen.getByText("管理模型…"));
    expect(useUiStore.getState().settingsOpen).toBe(true);
  });

  it("selection 指向的模型不在目录 → 胶囊照常显示该名（临时行兜底）", () => {
    useUiStore.getState().setProfilesPayload(payload);
    useAppStore.getState().setActiveSession("web-1", []);
    useAppStore.getState().setSelection({ profileId: "p-1", model: "custom-x", effort: "off" });
    render(<ModelPicker />);
    expect(screen.getByRole("button", { name: "选择模型" })).toHaveTextContent("custom-x");
    openPicker();
    expect(screen.getByRole("menuitemradio", { name: "custom-x" })).toBeInTheDocument();
  });

  it("目录为空时不渲染按钮", () => {
    useUiStore.getState().setProfilesPayload({ profiles: [], defaultProfile: "", defaultModel: "" });
    render(<ModelPicker />);
    expect(screen.queryByRole("button", { name: "选择模型" })).not.toBeInTheDocument();
  });
});
