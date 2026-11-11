import { create } from "zustand";
import type { AgentEvent } from "../lib/sse";
import type { SessionMeta, Status } from "../lib/api";

export type ToolCard = {
  id: string; name: string; input: any; result?: string;
  status: "running" | "ok" | "denied" | "error";
};
export type ChatMessage = {
  id: string; role: "user" | "assistant" | "notice" | "error";
  text: string; tools: ToolCard[];
};
export type PendingConfirm = { id: string; tool: string; input: any; preview: string };

let seq = 0;
const nid = () => `m${++seq}`;

type State = {
  backendUrl: string;
  status: Status | null;
  connected: boolean;
  sessions: SessionMeta[];
  activeSession: string;
  messages: ChatMessage[];
  streaming: boolean;
  pendingConfirm: PendingConfirm | null;
  error: string | null;

  setBackend: (url: string) => void;
  setStatus: (s: Status) => void;
  setConnected: (c: boolean) => void;
  setSessions: (s: SessionMeta[]) => void;
  setActiveSession: (id: string, messages?: ChatMessage[]) => void;
  setError: (e: string | null) => void;
  beginTurn: (text: string) => void;
  applyEvent: (ev: AgentEvent) => void;
  setPendingConfirm: (c: PendingConfirm | null) => void;
  endTurn: () => void;
  reset: () => void;
};

const lastAssistantIndex = (messages: ChatMessage[]): number => {
  for (let i = messages.length - 1; i >= 0; i--) {
    if (messages[i].role === "assistant") return i;
  }
  return -1;
};

export const useAppStore = create<State>((set, get) => ({
  backendUrl: "",
  status: null,
  connected: false,
  sessions: [],
  activeSession: "",
  messages: [],
  streaming: false,
  pendingConfirm: null,
  error: null,

  setBackend: (url) => set({ backendUrl: url }),
  setStatus: (s) => set({ status: s }),
  setConnected: (c) => set({ connected: c }),
  setSessions: (s) => set({ sessions: s }),
  setActiveSession: (id, messages = []) => set({ activeSession: id, messages, pendingConfirm: null, error: null }),
  setError: (e) => set({ error: e }),

  beginTurn: (text) =>
    set((st) => ({
      streaming: true,
      error: null,
      messages: [
        ...st.messages,
        { id: nid(), role: "user", text, tools: [] },
        { id: nid(), role: "assistant", text: "", tools: [] },
      ],
    })),

  // 与 brief 的差异：对 assistant 消息及其 tools 数组做不可变更新（克隆后替换），
  // 而非原地修改。Zustand v5 选择器按 Object.is 比较：原地 mutation 保持消息对象
  // 引用不变，按单条消息取用的选择器 / React.memo 行组件将不会重渲染，流式增量
  // 会显示滞后。公共状态形状与行为不变。
  applyEvent: (ev) => {
    const d = ev.data ?? {};
    const st = get();
    const msgs = st.messages.slice();
    const ai = lastAssistantIndex(msgs);
    const patchAssistant = (next: ChatMessage) => {
      if (ai >= 0) msgs[ai] = next;
    };
    switch (ev.type) {
      case "text_delta":
      case "thinking_delta":
        if (ai >= 0) patchAssistant({ ...msgs[ai], text: msgs[ai].text + (d.text ?? "") });
        break;
      case "tool_use_start":
        if (ai >= 0) {
          patchAssistant({
            ...msgs[ai],
            tools: [...msgs[ai].tools, { id: d.toolId, name: d.toolName, input: {}, status: "running" }],
          });
        }
        break;
      case "tool_use_end":
        if (ai >= 0) {
          const m = msgs[ai];
          patchAssistant({
            ...m,
            tools: m.tools.map((t) => (t.id === d.toolId ? { ...t, input: d.input ?? {} } : t)),
          });
        }
        break;
      case "tool_result": {
        if (ai >= 0) {
          const m = msgs[ai];
          const found = m.tools.findIndex((x) => x.name === d.toolName && x.status === "running");
          const ti = found >= 0 ? found : m.tools.length - 1;
          if (ti >= 0) {
            const result = String(d.result ?? "");
            const status: ToolCard["status"] = result.startsWith("DENIED:")
              ? "denied"
              : result.startsWith("ERROR:")
                ? "error"
                : "ok";
            patchAssistant({
              ...m,
              tools: m.tools.map((t, i) => (i === ti ? { ...t, result, status } : t)),
            });
          }
        }
        break;
      }
      case "confirm_request":
        set({ pendingConfirm: { id: d.id, tool: d.tool, input: d.input ?? {}, preview: d.preview ?? "" } });
        return;
      case "notice":
        msgs.push({ id: nid(), role: "notice", text: d.text ?? "", tools: [] });
        break;
      case "error":
        msgs.push({ id: nid(), role: "error", text: d.message ?? "未知错误", tools: [] });
        break;
      case "final":
        if (ai >= 0 && !msgs[ai].text) patchAssistant({ ...msgs[ai], text: d.text ?? "" });
        break;
    }
    set({ messages: msgs });
  },

  setPendingConfirm: (c) => set({ pendingConfirm: c }),
  endTurn: () => set({ streaming: false, pendingConfirm: null }),

  reset: () =>
    set({
      backendUrl: "", status: null, connected: false, sessions: [], activeSession: "",
      messages: [], streaming: false, pendingConfirm: null, error: null,
    }),
}));