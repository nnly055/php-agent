import { useAppStore } from "../store/useAppStore";
import { restartBackend } from "../lib/tauri";
import { Button } from "./ui/button";
import { WifiOff, RefreshCw } from "lucide-react";

export function ConnectionBanner({ onRetry }: { onRetry: () => void }) {
  const connected = useAppStore((s) => s.connected);
  const error = useAppStore((s) => s.error);
  if (connected && !error) return null;

  async function reconnect() {
    useAppStore.getState().setError(null);
    try {
      await restartBackend();
      onRetry();
    } catch (e: any) {
      useAppStore.getState().setError(e?.message ?? "重连失败");
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