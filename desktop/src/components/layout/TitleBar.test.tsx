import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { TitleBar } from "./TitleBar";
import { useAppStore } from "../../store/useAppStore";
import { useUiStore } from "../../store/useUiStore";

describe("TitleBar 配置入口", () => {
  beforeEach(() => {
    useAppStore.getState().reset();
    // useUiStore 无 reset：手动清空目录缓存，避免用例间串扰
    useUiStore.getState().setProfilesPayload(null);
    useUiStore.getState().setSettingsOpen(false);
  });

  it("model 未配置时徽章显示 未配置 且为警示色", () => {
    useUiStore.getState().setProfilesPayload(null);
    useAppStore.getState().setStatus({
      provider: "openai", model: "", workspace: "/tmp", permissions: "ask",
      sessionStore: "json", mcp: true, version: "0.2.0", maxIterations: 25,
    });
    render(<TitleBar />);
    const badge = screen.getByText(/openai\/未配置/);
    expect(badge).toBeInTheDocument();
    expect(badge.className).toContain("text-warning");
  });

  it("点击徽章打开设置对话框", () => {
    useAppStore.getState().setStatus({
      provider: "openai", model: "gpt-4o", workspace: "/tmp", permissions: "ask",
      sessionStore: "json", mcp: true, version: "0.2.0", maxIterations: 25,
    });
    render(<TitleBar />);
    fireEvent.click(screen.getByText(/openai\/gpt-4o/));
    expect(useUiStore.getState().settingsOpen).toBe(true);
  });

  it("有目录且当前会话有选择时，徽章显示 档名▸所选模型", () => {
    useUiStore.getState().setProfilesPayload({
      profiles: [
        { id: "p-1", name: "OpenAI", provider: "openai", apiKey: "", baseUrl: "", models: ["gpt-4o"] },
        { id: "p-2", name: "GLM", provider: "zhipu", apiKey: "", baseUrl: "", models: ["glm-4.6", "glm-4.7"] },
      ],
      defaultProfile: "p-1",
      defaultModel: "gpt-4o",
    });
    useAppStore.getState().setSelection({ profileId: "p-2", model: "glm-4.7" });
    render(<TitleBar />);
    expect(screen.getByText("GLM▸glm-4.7")).toBeInTheDocument();
  });

  it("selection 为空时回落默认档首个模型，不使用全局 defaultModel（显示=实际）", () => {
    // defaultModel ≠ 默认档首个模型：服务端从不使用 defaultModel，显示它就是谎报
    useUiStore.getState().setProfilesPayload({
      profiles: [
        { id: "p-1", name: "OpenAI", provider: "openai", apiKey: "", baseUrl: "", models: ["gpt-4o"] },
        { id: "p-2", name: "GLM", provider: "zhipu", apiKey: "", baseUrl: "", models: ["glm-4.6"] },
      ],
      defaultProfile: "p-1",
      defaultModel: "wrong-default",
    });
    render(<TitleBar />);
    expect(screen.getByText("OpenAI▸gpt-4o")).toBeInTheDocument();
  });
});
