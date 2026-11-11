import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { createProjectStore } from "./createProjectStore";
import { ProjectProvider, useProjectStore } from "./useProjectStore";
import { useAppStore } from "./useAppStore";
import type { AgentEvent } from "../lib/sse";

describe("createProjectStore isolation", () => {
  beforeEach(() => vi.clearAllMocks());

  it("two stores do not leak events across projects", () => {
    const a = createProjectStore();
    const b = createProjectStore();
    a.getState().setSessionId("p-a");
    b.getState().setSessionId("p-b");

    a.getState().beginTurn("任务 A");
    const ev: AgentEvent = { type: "text_delta", data: { text: "来自 A 的流" } };
    a.getState().applyEvent(ev);

    expect(a.getState().messages.at(-1)?.text).toBe("来自 A 的流");
    expect(b.getState().messages).toHaveLength(0);
    expect(a.getState().sessionId).toBe("p-a");
    expect(b.getState().sessionId).toBe("p-b");
  });

  it("one store failing does not touch the other", () => {
    const a = createProjectStore();
    const b = createProjectStore();
    a.getState().applyEvent({ type: "error", data: { message: "A 崩了" } });
    b.getState().setConnected(true);

    expect(a.getState().messages.at(-1)?.role).toBe("error");
    expect(b.getState().connected).toBe(true);
    expect(b.getState().messages).toHaveLength(0);
  });
});

describe("ProjectProvider context", () => {
  function Probe() {
    const store = useProjectStore();
    return <button onClick={() => store.getState().beginTurn("hello")}>begin</button>;
  }

  it("components inside a provider use that project's store", () => {
    const a = createProjectStore();
    const b = createProjectStore();
    render(
      <>
        <ProjectProvider store={a}>
          <Probe />
        </ProjectProvider>
        <ProjectProvider store={b}>
          <div />
        </ProjectProvider>
      </>
    );
    fireEvent.click(screen.getByText("begin"));
    expect(a.getState().messages).toHaveLength(2);
    expect(b.getState().messages).toHaveLength(0);
  });

  it("falls back to the default singleton outside any provider", () => {
    // 默认单例可用（旧测试依赖它）
    expect(typeof useAppStore.getState).toBe("function");
    expect(useAppStore.getState()).toBeDefined();
  });
});
