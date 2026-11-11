import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { Composer } from "./Composer";
import { useAppStore } from "../../store/useAppStore";

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