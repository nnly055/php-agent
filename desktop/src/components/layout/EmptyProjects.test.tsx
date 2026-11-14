import { describe, it, expect, vi } from "vitest";
import { render } from "@testing-library/react";
import { EmptyProjects, BootScreen } from "./EmptyProjects";

describe("空态拖拽区", () => {
  it("EmptyProjects 提供顶部拖拽区（macOS 无框化下窗口可拖动）", () => {
    const { container } = render(
      <EmptyProjects projects={[]} booting={null} onAdd={vi.fn()} onOpen={vi.fn()} onConfigure={vi.fn()} />
    );
    expect(container.querySelector("[data-tauri-drag-region]")).not.toBeNull();
  });

  it("BootScreen 整屏可拖拽", () => {
    const { container } = render(<BootScreen />);
    expect(container.querySelector("[data-tauri-drag-region]")).not.toBeNull();
  });
});
