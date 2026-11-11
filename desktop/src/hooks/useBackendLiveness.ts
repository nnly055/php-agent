import { useEffect } from "react";
import { getStatus } from "../lib/api";
import { useAppStore } from "../store/useAppStore";

/** 就绪后每 5s 探活 /status：成功置 connected=true，失败置 false（检测 sidecar 自发崩溃）。 */
export function useBackendLiveness(enabled: boolean, intervalMs = 5000): void {
  useEffect(() => {
    if (!enabled) return;
    let alive = true;
    let inFlight = false;
    const tick = async () => {
      if (inFlight) return;
      inFlight = true;
      try {
        // 4s 超时：sidecar 挂起（接受连接但不响应）时探活也会 reject，避免 inFlight 永久卡死轮询。
        const signal = typeof AbortSignal.timeout === "function" ? AbortSignal.timeout(4000) : undefined;
        await getStatus(signal);
        if (alive) useAppStore.getState().setConnected(true);
      } catch {
        if (alive) useAppStore.getState().setConnected(false);
      } finally {
        inFlight = false;
      }
    };
    const id = setInterval(tick, intervalMs);
    return () => {
      alive = false;
      clearInterval(id);
    };
  }, [enabled, intervalMs]);
}