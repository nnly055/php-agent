import type { ChatMessage } from "../../store/useAppStore";
import { Markdown } from "./Markdown";
import { ToolCallCard } from "./ToolCallCard";

export function MessageBubble({ msg, streaming }: { msg: ChatMessage; streaming?: boolean }) {
  if (msg.role === "notice") {
    return <div className="animate-fade-up self-start rounded-lg border border-dashed border-border px-3 py-2 text-xs text-info">ℹ {msg.text}</div>;
  }
  if (msg.role === "error") {
    return <div className="animate-fade-up self-start rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger">✗ {msg.text}</div>;
  }
  if (msg.role === "user") {
    return (
      <div className="animate-fade-up max-w-[80%] self-end whitespace-pre-wrap rounded-2xl rounded-br-sm border border-primary/30 bg-primary/15 px-4 py-2.5 text-sm text-ink">
        {msg.text}
      </div>
    );
  }
  return (
    <div className="animate-fade-up w-full max-w-[860px] self-start rounded-2xl rounded-bl-sm border border-border bg-surface px-4 py-3">
      {msg.tools.map((t) => <ToolCallCard key={t.id} tool={t} />)}
      {msg.text && <Markdown text={msg.text} />}
      {streaming && !msg.text && msg.tools.length === 0 && <span className="inline-block h-4 w-2 animate-blink bg-info align-middle" />}
      {streaming && msg.text && <span className="ml-0.5 inline-block h-4 w-2 animate-blink bg-info align-middle" />}
    </div>
  );
}