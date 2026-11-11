import { describe, it, expect, beforeEach } from "vitest";
import { useAppStore } from "./useAppStore";

describe("app store", () => {
  beforeEach(() => useAppStore.getState().reset());

  it("appends user message and starts streaming", () => {
    useAppStore.getState().beginTurn("hello");
    const s = useAppStore.getState();
    expect(s.streaming).toBe(true);
    expect(s.messages[0]).toMatchObject({ role: "user", text: "hello" });
    expect(s.messages[1].role).toBe("assistant");
  });

  it("applies text_delta to last assistant message", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "text_delta", data: { text: "abc" } });
    useAppStore.getState().applyEvent({ type: "text_delta", data: { text: "def" } });
    const msgs = useAppStore.getState().messages;
    expect(msgs[msgs.length - 1].text).toBe("abcdef");
  });

  it("sets pendingConfirm on confirm_request", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "confirm_request", data: { id: "cf1", tool: "write_file", input: {}, preview: "d" } });
    expect(useAppStore.getState().pendingConfirm).toMatchObject({ id: "cf1", tool: "write_file" });
  });

  it("tool_result marks tool ok and clears running", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "tool_use_start", data: { toolId: "t1", toolName: "write_file" } });
    useAppStore.getState().applyEvent({ type: "tool_result", data: { toolName: "write_file", result: "OK: 已写入" } });
    const tool = useAppStore.getState().messages.at(-1)!.tools[0];
    expect(tool.status).toBe("ok");
    expect(tool.result).toContain("OK");
  });
});