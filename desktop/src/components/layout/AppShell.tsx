import { TitleBar } from "./TitleBar";
import { Sidebar } from "./Sidebar";
import { StatusBar } from "./StatusBar";
import { ChatView } from "../chat/ChatView";

export function AppShell({
  onNewSession,
  onCloseProject,
}: {
  onNewSession: () => void;
  onCloseProject: () => void;
}) {
  return (
    <div className="flex h-full flex-col bg-bg text-ink">
      <TitleBar />
      <div className="flex min-h-0 flex-1">
        <Sidebar onNewSession={onNewSession} onCloseProject={onCloseProject} />
        <main className="flex min-w-0 flex-1 flex-col">
          <ChatView />
        </main>
      </div>
      <StatusBar />
    </div>
  );
}
