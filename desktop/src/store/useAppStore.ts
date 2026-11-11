// 兼容层：旧代码/旧测试 import { useAppStore } 仍指向模块级默认单例。
// 新代码（多项目）请使用 useProjectStore（Provider 注入各自项目的 store 实例）。
import { getDefaultProjectStore } from "./useProjectStore";

export const useAppStore = getDefaultProjectStore();
export type { ChatMessage, ToolCard, PendingConfirm } from "./createProjectStore";
