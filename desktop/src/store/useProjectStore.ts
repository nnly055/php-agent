import { createContext, useContext, createElement, type ReactNode } from "react";
import { createProjectStore, type ProjectStore } from "./createProjectStore";

/**
 * 项目 store 的 Context：一个 Provider = 一个项目（= 一个 PHP sidecar 进程）。
 * 组件在 Provider 内取各自项目的 store；无 Provider 时（旧单测直接 render）
 * 回退到模块级默认单例，保持旧测试零改动。
 */

let defaultStore: ProjectStore | null = null;
export function getDefaultProjectStore(): ProjectStore {
  return (defaultStore ??= createProjectStore());
}

const ProjectStoreCtx = createContext<ProjectStore | null>(null);

export function ProjectProvider({ store, children }: { store: ProjectStore; children: ReactNode }) {
  return createElement(ProjectStoreCtx.Provider, { value: store }, children);
}

/** 取当前项目的 store（bound：可当 hook 调用，也有 .getState()/.setState()） */
export function useProjectStore(): ProjectStore {
  return useContext(ProjectStoreCtx) ?? getDefaultProjectStore();
}
