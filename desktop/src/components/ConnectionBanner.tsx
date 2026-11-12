import { useProjectStore } from "../store/useProjectStore";
import { restartSession, getWorkspace } from "../lib/tauri";
import { Button } from "./ui/button";
import { WifiOff, RefreshCw } from "lucide-react";

export function ConnectionBanner({ onRetry }: { onRetry: () => void }) {
  const store = useProjectStore();
  const connected = store((s) => s.connected);
  const error = store((s) => s.error);
  if (connected && !error) return null;

  async function reconnect() {
    store.getState().setError(null);
    try {
      // workspace 校验当前 sidecar 存活；restart 按同 workspace 重拉进程
      await getWorkspace(store.getState().sessionId);
      // restart 产生新 sessionId 与端口：立即重绑，否则后续请求打到旧地址
      const opened = await restartSession(store.getState().sessionId);
      store.getState().setSessionId(opened.sessionId);
      store.getState().setBackend(`http://127.0.0.1:${opened.port}`);
      onRetry();
    } catch (e: any) {
      store.getState().setError(e?.message ?? "重连失败");
    }
  }

  return (
    <div className="flex items-center gap-3 border-b border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger">
      <WifiOff size={16} />
      <span>{connected ? error : "后端已断开——PHP sidecar 未运行"}</span>
      <Button size="sm" variant="outline" className="ml-auto gap-1 border-danger/40 text-danger hover:bg-danger/10" onClick={reconnect}>
        <RefreshCw size={13} /> 重连
      </Button>
    </div>
  );
}
