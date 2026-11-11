import { Elephant } from "./Elephant";
import { FileCode2, Terminal, FolderSearch } from "lucide-react";

const SUGGESTIONS = [
  { icon: <FolderSearch size={15} />, text: "列出当前目录的文件" },
  { icon: <FileCode2 size={15} />, text: "创建一个 hello.php 并运行验证" },
  { icon: <Terminal size={15} />, text: "用 grep 搜索项目里的 TODO" },
];

export function Welcome({ onPick }: { onPick: (t: string) => void }) {
  return (
    <div className="flex flex-col items-center gap-6 py-16 text-center">
      <Elephant className="h-20 w-20 text-primary" />
      <div>
        <h2 className="text-lg font-semibold tracking-tight text-ink">PHP Agent</h2>
        <p className="mt-1 max-w-sm text-sm text-muted">纯 PHP 的 coding agent。流式对话、工具调用、按需授权——开始一个任务吧。</p>
      </div>
      <div className="flex w-full max-w-md flex-col gap-2">
        {SUGGESTIONS.map((s) => (
          <button
            key={s.text}
            onClick={() => onPick(s.text)}
            className="flex items-center gap-3 rounded-xl border border-border bg-surface px-4 py-3 text-left text-sm text-ink transition-colors hover:border-primary/50 hover:bg-elevated"
          >
            <span className="text-primary">{s.icon}</span> {s.text}
          </button>
        ))}
      </div>
    </div>
  );
}