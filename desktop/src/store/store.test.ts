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

  it("tool_use_end assigns input by toolId", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "tool_use_start", data: { toolId: "t1", toolName: "write_file" } });
    useAppStore.getState().applyEvent({ type: "tool_use_end", data: { toolId: "t1", toolName: "write_file", input: { path: "a.php" } } });
    const tool = useAppStore.getState().messages.at(-1)!.tools[0];
    expect(tool.input).toEqual({ path: "a.php" });
  });

  it("tool_result with DENIED prefix marks tool denied", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "tool_use_start", data: { toolId: "t1", toolName: "write_file" } });
    useAppStore.getState().applyEvent({ type: "tool_result", data: { toolName: "write_file", result: "DENIED: 工具 write_file 被用户拒绝" } });
    const tool = useAppStore.getState().messages.at(-1)!.tools[0];
    expect(tool.status).toBe("denied");
  });

  it("tool_result with ERROR prefix marks tool error", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "tool_use_start", data: { toolId: "t1", toolName: "write_file" } });
    useAppStore.getState().applyEvent({ type: "tool_result", data: { toolName: "write_file", result: "ERROR: boom" } });
    const tool = useAppStore.getState().messages.at(-1)!.tools[0];
    expect(tool.status).toBe("error");
  });

  it("final fills only empty assistant text", () => {
    useAppStore.getState().beginTurn("hi");
    useAppStore.getState().applyEvent({ type: "final", data: { text: "answer" } });
    expect(useAppStore.getState().messages.at(-1)!.text).toBe("answer");
    useAppStore.getState().applyEvent({ type: "text_delta", data: { text: "x" } });
    const textBeforeFinal = useAppStore.getState().messages.at(-1)!.text;
    useAppStore.getState().applyEvent({ type: "final", data: { text: "y" } });
    // final 不覆盖非空文本：第二次 final 后文本保持不变
    expect(useAppStore.getState().messages.at(-1)!.text).toBe(textBeforeFinal);
  });

  it("text_delta updates messages immutably", () => {
    useAppStore.getState().beginTurn("hi");
    const msgsBefore = useAppStore.getState().messages;
    const userBefore = msgsBefore[0];
    const assistantBefore = msgsBefore[msgsBefore.length - 1];
    useAppStore.getState().applyEvent({ type: "text_delta", data: { text: "abc" } });
    const msgsAfter = useAppStore.getState().messages;
    expect(msgsAfter).not.toBe(msgsBefore);
    expect(msgsAfter[msgsAfter.length - 1]).not.toBe(assistantBefore);
    expect(msgsAfter[0]).toBe(userBefore);
  });
});