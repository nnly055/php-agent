export type AgentEvent = { type: string; data: any };

/** 从缓冲解析完整 SSE 帧（data: {...}\n\n），返回事件 + 剩余半帧 */
export function parseSseFrames(buffer: string): { events: AgentEvent[]; rest: string } {
  const events: AgentEvent[] = [];
  const frames = buffer.split("\n\n");
  const rest = frames.pop() ?? "";
  for (const frame of frames) {
    for (const line of frame.split("\n")) {
      if (!line.startsWith("data:")) continue;
      try {
        events.push(JSON.parse(line.slice(5).trim()));
      } catch {
        /* 坏帧跳过 */
      }
    }
  }
  return { events, rest };
}