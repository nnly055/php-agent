import { invoke } from "@tauri-apps/api/core";
import { listen, type UnlistenFn } from "@tauri-apps/api/event";

/** 一个打开的项目会话（= 一个 PHP sidecar 进程） */
export type OpenedSession = { sessionId: string; port: number; workspace: string };

export const listProjects = () => invoke<string[]>("list_projects");
export const getLastSession = () =>
  invoke<{ sessionId: string; workspace: string }>("get_last_session");
export const openProject = (path: string) => invoke<OpenedSession>("open_project", { path });
export const addProject = () => invoke<OpenedSession | null>("add_project");
export const closeProject = (sessionId: string) => invoke<boolean>("close_project", { sessionId });
export const removeProject = (path: string) => invoke<boolean>("remove_project", { path });
export const getBackendUrl = (sessionId: string) => invoke<string>("get_backend_url", { sessionId });
export const getWorkspace = (sessionId: string) => invoke<string>("get_workspace", { sessionId });
export const restartSession = (sessionId: string) =>
  invoke<OpenedSession>("restart_session", { sessionId });

/** 单个模型条目：name 必填；contextTokens/maxTokens 为可选数值（null/缺省 = 未设置，后端回落默认） */
export type ModelEntry = { name: string; contextTokens?: number | null; maxTokens?: number | null };
/** 一个平台档：name 供 UI 显示；models 为该平台可选模型条目列表（首个为默认）；camelCase 与后端序列化对齐 */
export type Profile = { id: string; name: string; provider: string; apiKey: string; baseUrl: string; models: ModelEntry[] };
export type ProfilesPayload = { profiles: Profile[]; defaultProfile: string; defaultModel: string };
export const getProfiles = () => invoke<ProfilesPayload>("get_profiles");
export const saveProfiles = (payload: ProfilesPayload) =>
  invoke<void>("save_profiles", { profiles: payload.profiles, defaultProfile: payload.defaultProfile, defaultModel: payload.defaultModel });

/** 一个 MCP server 条目：字段与 Rust McpServerEntry serde camelCase 对齐；timeout null = 默认 30 秒 */
export type McpServer = {
  name: string;
  command: string; args: string[]; env: Record<string, string>;
  url: string; headers: Record<string, string>;
  timeout?: number | null;
};
export type McpPayload = {
  global: McpServer[];
  project: McpServer[] | null;
  /** 层级读取错误（损坏/不可读）：字符串 = 该层不可用（UI 禁用该层保存并显示重试），null = 正常 */
  errors?: { global?: string | null; project?: string | null };
};
export const getMcp = (workspace?: string | null) =>
  invoke<McpPayload>("get_mcp", { workspace: workspace ?? null });
export const saveMcpGlobal = (servers: McpServer[]) =>
  invoke<void>("save_mcp_global", { servers });
export const saveMcpProject = (workspace: string, servers: McpServer[]) =>
  invoke<void>("save_mcp_project", { workspace, servers });

export type BackendEvent =
  | { kind: "ready"; sessionId: string; port: number; workspace: string }
  | { kind: "disconnected"; sessionId: string }
  | { kind: "error"; sessionId: string | null; message: string };

export async function onBackendEvent(cb: (e: BackendEvent) => void): Promise<UnlistenFn[]> {
  const subs = await Promise.all([
    listen<{ sessionId: string; port: number; workspace: string }>("backend://ready", (e) =>
      cb({ kind: "ready", sessionId: e.payload.sessionId, port: e.payload.port, workspace: e.payload.workspace })),
    listen<{ sessionId: string }>("backend://disconnected", (e) =>
      cb({ kind: "disconnected", sessionId: e.payload.sessionId })),
    listen<{ sessionId?: string; message: string }>("backend://error", (e) =>
      cb({ kind: "error", sessionId: e.payload.sessionId ?? null, message: e.payload.message })),
  ]);
  return subs;
}
