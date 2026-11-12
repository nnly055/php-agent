import { useCallback } from "react";
import { agentStream, confirm as apiConfirm, listSessions } from "../lib/api";
import { useProjectStore } from "../store/useProjectStore";

/** 当前项目（Provider 内）的回合发送/授权应答/中止。 */
export function useAgentStream() {
  const store = useProjectStore();

  const send = useCallback(async (text: string) => {
    const st = store.getState();
    if (st.streaming || !text.trim()) return;
    const sessionId = st.activeSession || `web-${Date.now().toString(36)}`;
    if (!st.activeSession) store.getState().setActiveSession(sessionId);
    store.getState().beginTurn(text);
    const ac = new AbortController();
    // store.streamAbort 为唯一来源：切会话（setActiveSession）会 abort 它防跨会话串数据。
    store.getState().setStreamAbort(ac);
    try {
      // 第 5 参携带本轮平台/模型选择；均缺省时 store 值为 {null,null}，agentStream 不会发送对应键
      for await (const ev of agentStream(st.backendUrl, text, sessionId, ac.signal, st.selection ?? undefined)) {
        store.getState().applyEvent(ev);
      }
    } catch (e: any) {
      if (e?.name !== "AbortError") store.getState().setError(e?.message ?? String(e));
    } finally {
      store.getState().endTurn();
      // 仅当当前控制器仍是本回合的才清空，避免切会话后新回合的控制器被旧 finally 误清。
      if (store.getState().streamAbort === ac) store.getState().setStreamAbort(null);
      // 回合结束后刷新会话列表，让新会话进侧栏（后端不可达时忽略，连接横幅会提示）
      try {
        const { sessions } = await listSessions(store.getState().backendUrl);
        store.getState().setSessions(sessions);
      } catch {
        /* ignore */
      }
    }
  }, [store]);

  const answerConfirm = useCallback(async (decision: "y" | "n" | "a") => {
    const c = store.getState().pendingConfirm;
    if (!c) return;
    store.getState().setPendingConfirm(null);
    try {
      await apiConfirm(store.getState().backendUrl, c.id, decision);
    } catch (e: any) {
      store.getState().setPendingConfirm(c); // 恢复弹窗，允许重试（后端仍在等待）
      store.getState().setError(e?.message ?? String(e));
    }
  }, [store]);

  const abort = useCallback(() => store.getState().streamAbort?.abort(), [store]);

  return { send, answerConfirm, abort };
}
