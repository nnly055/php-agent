import { parseDiffLines } from "../../lib/diff";
import { cn } from "../../lib/utils";

export function DiffView({ text }: { text: string }) {
  const lines = parseDiffLines(text);
  return (
    <pre className="overflow-x-auto rounded-lg border border-border bg-bg p-3 font-mono text-[12px] leading-relaxed">
      {lines.map((l, i) => (
        <div
          key={i}
          className={cn(
            "whitespace-pre",
            l.kind === "add" && "bg-success/10 text-success",
            l.kind === "del" && "bg-danger/10 text-danger",
            l.kind === "meta" && "text-muted",
            l.kind === "ctx" && "text-ink/80"
          )}
        >
          {l.text || " "}
        </div>
      ))}
    </pre>
  );
}
