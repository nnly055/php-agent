import { useEffect, useState } from "react";
import { AppShell } from "./components/layout/AppShell";
import { ConnectionBanner } from "./components/ConnectionBanner";
import { useAppStore } from "./store/useAppStore";
import { getBackendUrl, onBackendEvent } from "./lib/tauri";
import { getStatus, listSessions, setBaseUrl } from "./lib/api";

export default function App() {
  const [ready, setReady] = useState(false);
  const store = useAppStore;

  async function bootstrap() {
    try {
      const url = await getBackendUrl();
      setBaseUrl(url);
      store.getState().setBackend(url);
      const [status, sessions] = await Promise.all([getStatus(), listSessions()]);
      store.getState().setStatus(status);
      store.getState().setSessions(sessions.sessions);
      store.getState().setConnected(true);
      setReady(true);
    } catch {
      store.getState().setConnected(false);
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
        <div className="animate-pulse font-mono text-sm">正在连接 PHP 内核…</div>
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