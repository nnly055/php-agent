import type { SessionMeta } from "../../lib/api";
import { SessionItem } from "./SessionItem";

export function SessionList({ sessions }: { sessions: SessionMeta[] }) {
  if (sessions.length === 0) {
    return <div className="px-4 py-6 text-center text-xs text-muted">暂无会话</div>;
  }
  return (
    <div className="py-1">
      {sessions.map((s) => <SessionItem key={s.id} meta={s} />)}
    </div>
  );
}