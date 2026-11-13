import { useState, useRef, type KeyboardEvent, type ClipboardEvent } from "react";
import { useProjectStore } from "../../store/useProjectStore";
import { useUiStore } from "../../store/useUiStore";
import { Button } from "../ui/button";
import { SendHorizonal, Square, ImagePlus, X } from "lucide-react";
import { MAX_IMAGES, fileToAttachment } from "../../lib/imageFile";
import type { ImageAttachment } from "../../lib/api";

type Attachment = ImageAttachment & { previewUrl: string };

// 回收缩略图 object URL 防内存泄漏；个别环境（如 jsdom）缺 revokeObjectURL，缺失时跳过（仅滞留内存，无功能影响）
function revokePreview(url: string) {
  try {
    URL.revokeObjectURL(url);
  } catch {
    /* 环境不支持，忽略 */
  }
}

export function Composer({ onSend, onAbort }: { onSend: (t: string, images: ImageAttachment[]) => void; onAbort: () => void }) {
  const store = useProjectStore();
  const streaming = store((s) => s.streaming);
  const selection = store((s) => s.selection);
  const setSelection = store((s) => s.setSelection);
  const profilesPayload = useUiStore((s) => s.profilesPayload);
  const [text, setText] = useState("");
  const [attachments, setAttachments] = useState<Attachment[]>([]);
  // attachments 的同步镜像：addFiles 的配额判定要读"此刻"的长度，函数式 setState 的
  // updater 执行时机不保证同步（可能被 React 推迟到渲染期），据此计数会丢/错报超限提示。
  // 所有变更路径（增/删/清空）都经 setAtts 同步维护，检查与写入间无 await，天然防并发批次竞争。
  const attachmentsRef = useRef<Attachment[]>([]);
  const setAtts = (next: Attachment[]) => {
    attachmentsRef.current = next;
    setAttachments(next);
  };
  const ref = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // 对话内切换器：目录为空（无 profiles）时整行不渲染
  const profiles = profilesPayload?.profiles ?? [];
  const activeId = selection.profileId ?? profilesPayload?.defaultProfile ?? "";
  const activeProfile = profiles.find((p) => p.id === activeId);
  // 当前模型：会话选择 > 当前平台首个（defaultModel 不参与：服务端从不使用，显示≠实际会谎报）
  // 模型条目为对象（ModelEntry）：下拉只消费 name 字符串
  const currentModel = selection.model ?? activeProfile?.models[0]?.name ?? "";
  const models = activeProfile?.models.map((m) => m.name) ?? [];
  const modelTemp = currentModel !== "" && !models.includes(currentModel);
  // 平台同样兜底：selection 指向的档不在目录（如被删除）时插入临时 option 防空白
  const platformTemp = activeId !== "" && !profiles.some((p) => p.id === activeId);

  // 图片附件入口（选图 + 粘贴共用）：只收 image/*，且不超剩余配额；逐个预处理，失败走错误横幅。
  // 超限的文件一律计数并经 setError 提示（spec §2 超限提示）：批内超限在切片时点直接计入
  // （不浪费预处理），等待预处理期间配额被并发批次占满的在途丢弃也计入。
  async function addFiles(fileList: FileList | File[]) {
    const room = Math.max(0, MAX_IMAGES - attachmentsRef.current.length);
    const images = Array.from(fileList).filter((f) => f.type.startsWith("image/"));
    let ignored = Math.max(0, images.length - room);
    for (const f of images.slice(0, room)) {
      try {
        const att = await fileToAttachment(f);
        // 双保险：等待预处理期间配额可能被并发批次占满，仍不超 MAX_IMAGES（此丢弃同样计入提示）
        if (attachmentsRef.current.length >= MAX_IMAGES) {
          ignored += 1;
          continue;
        }
        setAtts([...attachmentsRef.current, att]);
      } catch (e) {
        store.getState().setError(e instanceof Error ? e.message : "图片处理失败");
      }
    }
    if (ignored > 0) {
      store.getState().setError(`最多添加 ${MAX_IMAGES} 张图片，已忽略 ${ignored} 张`);
    }
  }
  function removeAttachment(index: number) {
    const att = attachmentsRef.current[index];
    if (att) revokePreview(att.previewUrl);
    setAtts(attachmentsRef.current.filter((_, i) => i !== index));
  }
  function onPaste(e: ClipboardEvent<HTMLTextAreaElement>) {
    const files = e.clipboardData?.files;
    if (!files || files.length === 0) return; // 纯文本粘贴走默认行为
    if (!Array.from(files).some((f) => f.type.startsWith("image/"))) return;
    void addFiles(files).catch(() => {});
  }

  function submit() {
    const t = text.trim();
    if ((!t && attachments.length === 0) || streaming) return;
    // 只上送 wire 字段，previewUrl 是本地 object URL 不外发
    onSend(t, attachments.map(({ mediaType, data }) => ({ mediaType, data })));
    attachments.forEach((a) => revokePreview(a.previewUrl));
    setAtts([]);
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
                // 切平台回落该档首个模型名，避免带着别的平台的模型名发请求
                const first = profiles.find((p) => p.id === id)?.models[0]?.name ?? null;
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
        {attachments.length > 0 && (
          <div className="mb-2 flex flex-wrap gap-2">
            {attachments.map((a, i) => (
              <div key={a.previewUrl} className="relative">
                <img src={a.previewUrl} alt={`图片附件 ${i + 1}`} className="h-16 w-16 rounded-lg border border-border object-cover" />
                <button
                  type="button"
                  aria-label="移除图片"
                  onClick={() => removeAttachment(i)}
                  className="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-danger text-white"
                >
                  <X size={12} />
                </button>
              </div>
            ))}
          </div>
        )}
        <div className="flex items-end gap-2">
          <Button
            variant="outline"
            size="icon"
            className="h-11 w-11 shrink-0 text-muted hover:text-ink"
            onClick={() => fileInputRef.current?.click()}
            title="添加图片"
          >
            <ImagePlus size={18} />
          </Button>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            multiple
            aria-label="添加图片"
            className="hidden"
            onChange={(e) => {
              if (e.target.files?.length) void addFiles(e.target.files);
              e.target.value = ""; // 允许再次选择同一文件
            }}
          />
          <textarea
            ref={ref}
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={onKey}
            onPaste={onPaste}
            rows={1}
            placeholder="输入任务…（Enter 发送，Shift+Enter 换行）"
            className="max-h-40 flex-1 resize-none rounded-xl border border-border bg-bg px-4 py-3 text-sm text-ink outline-none transition-colors placeholder:text-muted focus:border-primary"
          />
          {streaming ? (
            <Button variant="outline" size="icon" className="h-11 w-11 border-danger/40 text-danger" onClick={onAbort} title="停止">
              <Square size={16} />
            </Button>
          ) : (
            <Button size="icon" className="h-11 w-11 bg-primary-deep text-white hover:bg-primary" onClick={submit} disabled={!text.trim() && attachments.length === 0} title="发送">
              <SendHorizonal size={18} />
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
