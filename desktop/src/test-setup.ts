import "@testing-library/jest-dom/vitest";

// jsdom 缺少 Radix（dropdown-menu / alert-dialog 的 popper 与焦点管理）依赖的 API，
// 仅在缺失时补最小 stub，不影响真实浏览器行为。
if (typeof globalThis.ResizeObserver === "undefined") {
  globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
}
if (typeof globalThis.PointerEvent === "undefined") {
  // jsdom 无 PointerEvent：testing-library 会退化成 Event 而丢失 button/pointerType，
  // Radix trigger 的 event.button === 0 判断将失败。用 MouseEvent 子类补齐。
  globalThis.PointerEvent = class extends MouseEvent {
    pointerId: number;
    pointerType: string;
    constructor(type: string, init: PointerEventInit = {}) {
      super(type, init);
      this.pointerId = init.pointerId ?? 0;
      this.pointerType = init.pointerType ?? "";
    }
  } as unknown as typeof PointerEvent;
}
if (typeof Element !== "undefined") {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
}