import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { ConfirmDialog } from "./ConfirmDialog";
import { useAppStore } from "../../store/useAppStore";

describe("ConfirmDialog", () => {
  beforeEach(() => {
    useAppStore.getState().reset();
    useAppStore.getState().setPendingConfirm({ id: "cf1", tool: "write_file", input: { path: "a.php" }, preview: "+new" });
  });

  it("renders tool and preview", () => {
    render(<ConfirmDialog onAnswer={() => {}} />);
    expect(screen.getByText("write_file")).toBeInTheDocument();
  });

  it("keyboard Y answers yes", () => {
    const onAnswer = vi.fn();
    render(<ConfirmDialog onAnswer={onAnswer} />);
    fireEvent.keyDown(window, { key: "y" });
    expect(onAnswer).toHaveBeenCalledWith("y");
  });

  it("Escape answers no", () => {
    const onAnswer = vi.fn();
    render(<ConfirmDialog onAnswer={onAnswer} />);
    fireEvent.keyDown(window, { key: "Escape" });
    expect(onAnswer).toHaveBeenCalledWith("n");
  });
});