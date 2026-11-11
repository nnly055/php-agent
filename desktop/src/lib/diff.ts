export type DiffLine = { kind: "add" | "del" | "meta" | "ctx"; text: string };

const ANSI = /\x1b\[[0-9;]*m/g;

export function parseDiffLines(text: string): DiffLine[] {
  return text.replace(ANSI, "").split("\n").map((line) => {
    if (/^(\+\+\+|---|@@)/.test(line)) return { kind: "meta", text: line };
    if (line.startsWith("+")) return { kind: "add", text: line };
    if (line.startsWith("-")) return { kind: "del", text: line };
    return { kind: "ctx", text: line };
  });
}

export function looksLikeDiff(text: string): boolean {
  const plain = text.replace(ANSI, "");
  return plain.includes("\n+") || plain.includes("\n-");
}