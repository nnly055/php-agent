import { describe, it, expect } from "vitest";
import { nextScale, nextQuality } from "./imageFile";

describe("nextScale", () => {
  it("长边超 2000 才缩放", () => {
    expect(nextScale(4000, 2000)).toBeCloseTo(0.5);
    expect(nextScale(1999, 1000)).toBeNull();
  });
});
describe("nextQuality", () => {
  it("质量档序列 0.9→0.8→0.7→0.6，耗尽返回 null", () => {
    expect(nextQuality(null)).toBe(0.9);
    expect(nextQuality(0.9)).toBe(0.8);
    expect(nextQuality(0.6)).toBeNull();
  });
});
