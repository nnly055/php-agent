import { parseSseFrames, type AgentEvent } from "./sse";

// baseUrl 显式参数化：多项目 = 多 sidecar = 多连接，不允许全局隐式 base。
// 旧的单参调用点已全部迁移（store 里持有各自的 backendUrl）。

async function json<T>(baseUrl: string, path: string, init?: RequestInit): Promise<T> {
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

export const getStatus = (baseUrl: string, signal?: AbortSignal) => json<Status>(baseUrl, "/status", { signal });

export type ConfigTestResult = { ok: boolean; message: string; latency_ms: number };
/** POST /config/test：测试连接（桌面设置对话框用；走任意已连接 sidecar 的 baseUrl） */
export const testConfig = (baseUrl: string, cfg: { provider: string; apiKey: string; baseUrl: string; model: string }) =>
  json<ConfigTestResult>(baseUrl, "/config/test", {
    method: "POST",
    body: JSON.stringify({ provider: cfg.provider, api_key: cfg.apiKey, base_url: cfg.baseUrl, model: cfg.model }),
  });
export const listSessions = (baseUrl: string, limit = 50) => json<{ sessions: SessionMeta[] }>(baseUrl, `/sessions?limit=${limit}`);
export const loadSession = (baseUrl: string, id: string) => json<{ id: string; messages: any[] }>(baseUrl, `/sessions/${encodeURIComponent(id)}`);
export const deleteSession = (baseUrl: string, id: string) => json<{ ok: boolean }>(baseUrl, `/sessions/${encodeURIComponent(id)}`, { method: "DELETE" });
export const confirm = (baseUrl: string, id: string, decision: "y" | "n" | "a") =>
  json<{ ok: boolean }>(baseUrl, `/confirm/${encodeURIComponent(id)}`, { method: "POST", body: JSON.stringify({ decision }) });

/** POST /agent → SSE 事件异步迭代器 */
export async function* agentStream(baseUrl: string, message: string, sessionId: string, signal?: AbortSignal): AsyncGenerator<AgentEvent> {
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
