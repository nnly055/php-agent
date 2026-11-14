import { describe, it, expect } from "vitest";
import { relativeTime } from "./relativeTime";

describe("relativeTime", () => {
  const now = 1_800_000_000_000;
  it("<60s → 刚刚", () => expect(relativeTime(now - 30_000, now)).toBe("刚刚"));
  it("<60min → n分", () => expect(relativeTime(now - 4 * 60_000, now)).toBe("4分"));
  it("<24h → n小时", () => expect(relativeTime(now - 3 * 3_600_000, now)).toBe("3小时"));
  it("<7d → n天", () => expect(relativeTime(now - 2 * 86_400_000, now)).toBe("2天"));
  it("≥7d → n周", () => expect(relativeTime(now - 10 * 604_800_000, now)).toBe("10周"));
});
