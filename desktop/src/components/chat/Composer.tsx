import { useState, useRef, type KeyboardEvent } from "react";
import { useProjectStore } from "../../store/useProjectStore";
import { useUiStore } from "../../store/useUiStore";
import { Button } from "../ui/button";
import { SendHorizonal, Square } from "lucide-react";

export function Composer({ onSend, onAbort }: { onSend: (t: string) => void; onAbort: () => void }) {
  const store = useProjectStore();
  const streaming = store((s) => s.streaming);
  const selection = store((s) => s.selection);
  const setSelection = store((s) => s.setSelection);
  const profilesPayload = useUiStore((s) => s.profilesPayload);
  const [text, setText] = useState("");
  const ref = useRef<HTMLTextAreaElement>(null);

  // 对话内切换器：目录为空（无 profiles）时整行不渲染
  const profiles = profilesPayload?.profiles ?? [];
  const activeId = selection.profileId ?? profilesPayload?.defaultProfile ?? "";
  const activeProfile = profiles.find((p) => p.id === activeId);
  // 当前模型：会话选择 > 当前平台首个（defaultModel 不参与：服务端从不使用，显示≠实际会谎报）
  const currentModel = selection.model ?? activeProfile?.models[0] ?? "";
  const models = activeProfile?.models ?? [];
  const modelTemp = currentModel !== "" && !models.includes(currentModel);
  // 平台同样兜底：selection 指向的档不在目录（如被删除）时插入临时 option 防空白
  const platformTemp = activeId !== "" && !profiles.some((p) => p.id === activeId);

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
      <div className="mx-auto max-w-[860px]">
        {profiles.length > 0 && (
          <div className="mb-2 flex items-center gap-2 text-xs text-muted">
            <select
              aria-label="平台"
              value={activeId}
              onChange={(e) => {
                const id = e.target.value;
                // 切平台回落该档首个模型，避免带着别的平台的模型名发请求
                const first = profiles.find((p) => p.id === id)?.models[0] ?? null;
                setSelection({ profileId: id, model: first });
              }}
              className="rounded-md border border-border bg-bg px-2 py-1 text-xs text-ink outline-none transition-colors focus:border-primary"
            >
              {platformTemp && <option value={activeId}>{activeId}</option>}
              {profiles.map((p) => (
                <option key={p.id} value={p.id}>{p.name || p.provider}</option>
              ))}
            </select>
            <select
              aria-label="模型"
              value={currentModel}
              onChange={(e) => setSelection({ profileId: activeId, model: e.target.value })}
              className="rounded-md border border-border bg-bg px-2 py-1 text-xs text-ink outline-none transition-colors focus:border-primary"
            >
              {modelTemp && <option value={currentModel}>{currentModel}</option>}
              {models.map((m) => (
                <option key={m} value={m}>{m}</option>
              ))}
            </select>
          </div>
        )}
        <div className="flex items-end gap-2">
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
    </div>
  );
}
