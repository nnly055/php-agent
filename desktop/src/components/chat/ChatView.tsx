import { useEffect, useRef } from "react";
import { useAppStore } from "../../store/useAppStore";
import { useAgentStream } from "../../hooks/useAgentStream";
import { MessageBubble } from "./MessageBubble";
import { Composer } from "./Composer";
import { Welcome } from "../Welcome";
import { ConfirmDialog } from "../confirm/ConfirmDialog";

export function ChatView() {
  const messages = useAppStore((s) => s.messages);
  const streaming = useAppStore((s) => s.streaming);
  const { send, answerConfirm, abort } = useAgentStream();
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: "smooth" }); }, [messages, streaming]);

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="flex-1 overflow-y-auto px-6 py-5">
        <div className="mx-auto flex max-w-[860px] flex-col gap-4">
          {messages.length === 0 ? (
            <Welcome onPick={send} />
          ) : (
            messages.map((m, i) => (
              <MessageBubble key={m.id} msg={m} streaming={streaming && i === messages.length - 1 && m.role === "assistant"} />
            ))
          )}
          <div ref={endRef} />
        </div>
      </div>
      <Composer onSend={send} onAbort={abort} />
      <ConfirmDialog onAnswer={answerConfirm} />
    </div>
  );
}