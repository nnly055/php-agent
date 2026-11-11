import { useRef, useCallback } from "react";
import { agentStream, confirm as apiConfirm } from "../lib/api";
import { useAppStore } from "../store/useAppStore";

export function useAgentStream() {
  const abortRef = useRef<AbortController | null>(null);
  const store = useAppStore;

  const send = useCallback(async (text: string) => {
    const st = store.getState();
    if (st.streaming || !text.trim()) return;
    const sessionId = st.activeSession || `web-${Date.now().toString(36)}`;
    if (!st.activeSession) store.getState().setActiveSession(sessionId);
    store.getState().beginTurn(text);
    const ac = new AbortController();
    abortRef.current = ac;
    try {
      for await (const ev of agentStream(text, sessionId, ac.signal)) {
        store.getState().applyEvent(ev);
      }
    } catch (e: any) {
      if (e?.name !== "AbortError") store.getState().setError(e?.message ?? String(e));
    } finally {
      store.getState().endTurn();
      abortRef.current = null;
    }
  }, []);

  const answerConfirm = useCallback(async (decision: "y" | "n" | "a") => {
    const c = store.getState().pendingConfirm;
    if (!c) return;
    store.getState().setPendingConfirm(null);
    try {
      await apiConfirm(c.id, decision);
    } catch (e: any) {
      store.getState().setError(e?.message ?? String(e));
    }
  }, []);

  const abort = useCallback(() => abortRef.current?.abort(), []);

  return { send, answerConfirm, abort };
}