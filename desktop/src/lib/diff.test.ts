import { describe, it, expect } from "vitest";
import { parseDiffLines } from "./diff";

describe("parseDiffLines", () => {
  it("classifies add/del/meta/context", () => {
    const lines = parseDiffLines("--- a\n+++ b\n@@ -1 +1 @@\n-old\n+new\n same");
    expect(lines.map((l) => l.kind)).toEqual(["meta", "meta", "meta", "del", "add", "ctx"]);
  });
  it("strips ansi codes", () => {
    const lines = parseDiffLines("\x1b[32m+added\x1b[0m");
    expect(lines[0].kind).toBe("add");
    expect(lines[0].text).toBe("+added");
  });
});