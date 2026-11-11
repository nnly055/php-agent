import { describe, it, expect } from "vitest";
import { parseSseFrames } from "./sse";

describe("parseSseFrames", () => {
  it("parses complete frames and keeps partial rest", () => {
    const buf = 'data: {"type":"text_delta","data":{"text":"hi"}}\n\ndata: {"type":"final","data":{"text":"ok"}}\n\ndata: {"type":"text';
    const { events, rest } = parseSseFrames(buf);
    expect(events).toHaveLength(2);
    expect(events[0].type).toBe("text_delta");
    expect(events[1].type).toBe("final");
    expect(rest).toBe('data: {"type":"text');
  });

  it("ignores malformed json frames", () => {
    const { events } = parseSseFrames("data: not-json\n\n");
    expect(events).toHaveLength(0);
  });

  it("handles empty buffer", () => {
    const { events, rest } = parseSseFrames("");
    expect(events).toHaveLength(0);
    expect(rest).toBe("");
  });
});