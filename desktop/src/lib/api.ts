import { parseSseFrames, type AgentEvent } from "./sse";

let baseUrl = "";
export const setBaseUrl = (url: string) => { baseUrl = url; };
export const getBaseUrl = () => baseUrl;

async function json<T>(path: string, init?: RequestInit): Promise<T> {
  const resp = await fetch(baseUrl + path, {
    headers: { "Content-Type": "application/json" },
    ...init,
  });
  if (!resp.ok) {
    const err = await resp.json().catch(() => ({}));
    throw new Error((err as any).error || `HTTP ${resp.status}`);
  }
  return resp.json() as Promise<T>;
}

export type Status = {
  provider: string; model: string; workspace: string; permissions: string;
  sessionStore: string; mcp: boolean; version: string; maxIterations: number;
};
export type SessionMeta = { id: string; title: string; createdAt: number; updatedAt: number; messageCount: number };

export const getStatus = () => json<Status>("/status");
export const listSessions = (limit = 50) => json<{ sessions: SessionMeta[] }>(`/sessions?limit=${limit}`);
export const loadSession = (id: string) => json<{ id: string; messages: any[] }>(`/sessions/${encodeURIComponent(id)}`);
export const deleteSession = (id: string) => json<{ ok: boolean }>(`/sessions/${encodeURIComponent(id)}`, { method: "DELETE" });
export const confirm = (id: string, decision: "y" | "n" | "a") =>
  json<{ ok: boolean }>(`/confirm/${encodeURIComponent(id)}`, { method: "POST", body: JSON.stringify({ decision }) });

/** POST /agent → SSE 事件异步迭代器 */
export async function* agentStream(message: string, sessionId: string, signal?: AbortSignal): AsyncGenerator<AgentEvent> {
  const resp = await fetch(baseUrl + "/agent", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ message, session_id: sessionId }),
    signal,
  });
  if (!resp.ok || !resp.body) {
    const err = await resp.json().catch(() => ({}));
    throw new Error((err as any).error || `HTTP ${resp.status}`);
  }
  const reader = resp.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const { events, rest } = parseSseFrames(buffer);
    buffer = rest;
    for (const ev of events) yield ev;
  }
}