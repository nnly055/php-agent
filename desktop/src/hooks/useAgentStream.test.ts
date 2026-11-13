import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { useAgentStream } from "./useAgentStream";
import { useAppStore } from "../store/useAppStore";
import * as api from "../lib/api";

vi.mock("../lib/api", () => ({
  confirm: vi.fn(),
  agentStream: vi.fn(),
  listSessions: vi.fn(),
}));

describe("useAgentStream.answerConfirm", () => {
  beforeEach(() => { useAppStore.getState().reset(); vi.clearAllMocks(); });

  it("restores pendingConfirm when POST fails", async () => {
    (api.confirm as any).mockRejectedValueOnce(new Error("network"));
    useAppStore.getState().setPendingConfirm({ id: "cf1", tool: "write_file", input: {}, preview: "" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.answerConfirm("y"); });
    expect(useAppStore.getState().pendingConfirm).toMatchObject({ id: "cf1" });
    expect(useAppStore.getState().error).toBe("network");
  });

  it("clears pendingConfirm on success", async () => {
    (api.confirm as any).mockResolvedValueOnce({ ok: true });
    useAppStore.getState().setPendingConfirm({ id: "cf2", tool: "run_command", input: {}, preview: "" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.answerConfirm("n"); });
    expect(useAppStore.getState().pendingConfirm).toBeNull();
    // baseUrl 参数化后：第一个参数为 backendUrl（默认单例为 ""）
    expect(api.confirm).toHaveBeenCalledWith(expect.any(String), "cf2", "n");
  });

  it("refreshes the session list after a turn completes", async () => {
    // mock agentStream as an empty async generator (turn ends immediately)
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    (api.listSessions as any).mockResolvedValueOnce({ sessions: [{ id: "s-new", title: "演示", createdAt: 0, updatedAt: 0, messageCount: 2 }] });
    useAppStore.getState().setActiveSession("s-new", []);
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("演示"); });
    expect(api.listSessions).toHaveBeenCalled();
    expect(useAppStore.getState().sessions.map(s => s.id)).toContain("s-new");
    expect(useAppStore.getState().streaming).toBe(false);
  });

  it("passes the store selection to agentStream as the 5th arg", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    useAppStore.getState().setActiveSession("s-sel", []);
    useAppStore.getState().setSelection({ profileId: "p-9", model: "m-9" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("带选择发送"); });
    expect(api.agentStream).toHaveBeenCalledWith(
      expect.any(String), "带选择发送", "s-sel", expect.any(AbortSignal), { profileId: "p-9", model: "m-9" }, undefined,
    );
  });

  it("selection 默认 { null, null } 也原样透传（wire 由 agentStream 过滤空键）", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    useAppStore.getState().setActiveSession("s-def", []);
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("默认发送"); });
    expect(api.agentStream).toHaveBeenLastCalledWith(
      expect.any(String), "默认发送", "s-def", expect.any(AbortSignal), { profileId: null, model: null }, undefined,
    );
  });

  it("新会话发送后 selection 保持用户所选（重置不吞掉发送前选择）", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    // 不预设 activeSession：走 send() 内的"新会话"分支（setActiveSession 会重置 selection）
    useAppStore.getState().setSelection({ profileId: "p-2", model: "glm-4.7" });
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("新会话首条"); });
    // 消息 1 携带用户所选
    expect(api.agentStream).toHaveBeenLastCalledWith(
      expect.any(String), "新会话首条", expect.any(String), expect.any(AbortSignal), { profileId: "p-2", model: "glm-4.7" }, undefined,
    );
    // send 完成后 store 仍保持所选（composer 不跳回默认档）
    expect(useAppStore.getState().selection).toEqual({ profileId: "p-2", model: "glm-4.7" });
  });

  it("新会话未选时保持重置态（继承默认档）", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("未选发送"); });
    expect(useAppStore.getState().selection).toEqual({ profileId: null, model: null });
    expect(api.agentStream).toHaveBeenLastCalledWith(
      expect.any(String), "未选发送", expect.any(String), expect.any(AbortSignal), { profileId: null, model: null }, undefined,
    );
  });
});

describe("useAgentStream.send images 透传", () => {
  beforeEach(() => { useAppStore.getState().reset(); vi.clearAllMocks(); });

  it("send 把 images 透传给 agentStream 并进乐观用户气泡", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    useAppStore.getState().setActiveSession("s-img", []);
    const img = { mediaType: "image/png", data: "AAAA" };
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("hi", [img]); });
    // camelCase images 原样透传给 agentStream 第 6 参（snake_case 映射由 api 层负责）
    expect(api.agentStream).toHaveBeenLastCalledWith(
      expect.any(String), "hi", "s-img", expect.any(AbortSignal), { profileId: null, model: null }, [img],
    );
    expect(useAppStore.getState().messages[0]).toMatchObject({ role: "user", text: "hi", images: [img] });
  });

  it("未传 images 时第 6 参为 undefined，乐观气泡无 images 字段", async () => {
    (api.agentStream as any).mockImplementationOnce(async function* () {});
    useAppStore.getState().setActiveSession("s-plain", []);
    const { result } = renderHook(() => useAgentStream());
    await act(async () => { await result.current.send("纯文本"); });
    const call = (api.agentStream as any).mock.calls.at(-1) as unknown[];
    expect(call[5]).toBeUndefined();
    expect(useAppStore.getState().messages[0].images).toBeUndefined();
  });
});

describe("agentStream 请求体 images（真实 api 层，wire snake_case）", () => {
  afterEach(() => { vi.unstubAllGlobals(); });

  // 真实 agentStream 消费 fetch 的 SSE body：mock 流立即结束即可
  const mockSseFetch = () => {
    const fetchMock = vi.fn(async () => ({
      ok: true,
      body: { getReader: () => ({ read: async () => ({ done: true, value: undefined }) }) },
      json: async () => ({}),
    }));
    vi.stubGlobal("fetch", fetchMock);
    return fetchMock;
  };

  it("携带 images 时请求体含 snake_case images", async () => {
    const fetchMock = mockSseFetch();
    const { agentStream: realAgentStream } = await vi.importActual<typeof import("../lib/api")>("../lib/api");
    const gen = realAgentStream("", "hi", "s-wire", undefined, undefined, [{ mediaType: "image/png", data: "AAAA" }]);
    expect((await gen.next()).done).toBe(true);
    const body = JSON.parse((fetchMock.mock.calls[0] as any)[1].body);
    expect(body.images).toEqual([{ media_type: "image/png", data: "AAAA" }]);
  });

  it("未传 images 时请求体不含 images 键（无空数组噪音）", async () => {
    const fetchMock = mockSseFetch();
    const { agentStream: realAgentStream } = await vi.importActual<typeof import("../lib/api")>("../lib/api");
    const gen = realAgentStream("", "hi", "s-wire");
    expect((await gen.next()).done).toBe(true);
    const body = JSON.parse((fetchMock.mock.calls[0] as any)[1].body);
    expect(body).not.toHaveProperty("images");
  });
});