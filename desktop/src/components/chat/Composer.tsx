import { useState, useRef, type KeyboardEvent } from "react";
import { useProjectStore } from "../../store/useProjectStore";
import { Button } from "../ui/button";
import { SendHorizonal, Square } from "lucide-react";

export function Composer({ onSend, onAbort }: { onSend: (t: string) => void; onAbort: () => void }) {
  const store = useProjectStore();
  const streaming = store((s) => s.streaming);
  const [text, setText] = useState("");
  const ref = useRef<HTMLTextAreaElement>(null);

  function submit() {
    const t = text.trim();
    if (!t || streaming) return;
    onSend(t);
    setText("");
  }
  function onKey(e: KeyboardEvent) {
    // IME 组合态（如中文拼音选词）的回车用于确认候选词，不应触发发送
    if (e.nativeEvent.isComposing) return;
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); submit(); }
  }

  return (
    <div className="shrink-0 border-t border-border bg-surface p-3">
      <div className="mx-auto flex max-w-[860px] items-end gap-2">
        <textarea
          ref={ref}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={onKey}
          rows={1}
          placeholder="输入任务…（Enter 发送，Shift+Enter 换行）"
          className="max-h-40 flex-1 resize-none rounded-xl border border-border bg-bg px-4 py-3 text-sm text-ink outline-none transition-colors placeholder:text-muted focus:border-primary"
        />
        {streaming ? (
          <Button variant="outline" size="icon" className="h-11 w-11 border-danger/40 text-danger" onClick={onAbort} title="停止">
            <Square size={16} />
          </Button>
        ) : (
          <Button size="icon" className="h-11 w-11 bg-primary-deep text-white hover:bg-primary" onClick={submit} disabled={!text.trim()} title="发送">
            <SendHorizonal size={18} />
          </Button>
        )}
      </div>
    </div>
  );
}