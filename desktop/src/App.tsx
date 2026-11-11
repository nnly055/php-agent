import { useEffect, useState } from "react";
import { AppShell } from "./components/layout/AppShell";
import { ConnectionBanner } from "./components/ConnectionBanner";
import { useAppStore } from "./store/useAppStore";
import { useBackendLiveness } from "./hooks/useBackendLiveness";
import { getBackendUrl, onBackendEvent } from "./lib/tauri";
import { getStatus, listSessions, setBaseUrl } from "./lib/api";

export default function App() {
  const [ready, setReady] = useState(false);
  const [bootError, setBootError] = useState<string | null>(null);
  const store = useAppStore;

  useBackendLiveness(ready);

  async function bootstrap() {
    try {
      setBootError(null);
      const url = await getBackendUrl();
      setBaseUrl(url);
      store.getState().setBackend(url);
      const [status, sessions] = await Promise.all([getStatus(), listSessions()]);
      store.getState().setStatus(status);
      store.getState().setSessions(sessions.sessions);
      store.getState().setConnected(true);
      setReady(true);
    } catch (e: any) {
      store.getState().setConnected(false);
      setBootError(e?.message ?? "无法连接 PHP 内核");
    }
  }

  useEffect(() => {
    bootstrap();
    const off = onBackendEvent((e) => {
      if (e.kind === "ready") bootstrap();
      else if (e.kind === "disconnected") store.getState().setConnected(false);
      else if (e.kind === "error") store.getState().setError(e.message);
    });
    return () => { off.then((fns) => fns.forEach((f) => f())); };
  }, []);

  function newSession() {
    store.getState().setActiveSession(`web-${Date.now().toString(36)}`, []);
  }

  if (!ready) {
    return (
      <div className="grid h-screen place-items-center bg-bg text-muted">
        {bootError ? (
          <div className="flex max-w-sm flex-col items-center gap-4 text-center">
            <div className="text-sm text-danger">✗ {bootError}</div>
            <button
              onClick={() => { setBootError(null); bootstrap(); }}
              className="rounded-lg bg-primary-deep px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary"
            >
              重试连接
            </button>
          </div>
        ) : (
          <div className="animate-pulse font-mono text-sm">正在连接 PHP 内核…</div>
        )}
      </div>
    );
  }

  return (
    <>
      <ConnectionBanner onRetry={bootstrap} />
      <AppShell onNewSession={newSession} />
    </>
  );
}