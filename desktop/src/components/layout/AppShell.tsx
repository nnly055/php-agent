import type { ReactNode } from "react";

/** 顶层骨架：侧栏 + 主列。主列内容由 App 注入（Provider 包裹的横幅/标题栏/聊天）。 */
export function AppShell({ sidebar, main }: { sidebar: ReactNode; main: ReactNode }) {
  return (
    <div className="flex h-screen bg-bg text-ink">
      {sidebar}
      <main className="flex min-w-0 flex-1 flex-col">{main}</main>
    </div>
  );
}
