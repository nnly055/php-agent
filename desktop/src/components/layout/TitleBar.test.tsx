import { describe, it, expect, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { TitleBar } from "./TitleBar";
import { useAppStore } from "../../store/useAppStore";
import { useUiStore } from "../../store/useUiStore";

describe("TitleBar 配置入口", () => {
  beforeEach(() => {
    useAppStore.getState().reset();
    useUiStore.getState().setSettingsOpen(false);
  });

  it("model 未配置时徽章显示 未配置 且为警示色", () => {
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
});
