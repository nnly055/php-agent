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

/** 一个平台档：name 供 UI 显示；models 为该平台可选模型列表（首个为默认）；camelCase 与后端序列化对齐 */
export type Profile = { id: string; name: string; provider: string; apiKey: string; baseUrl: string; models: string[] };
export type ProfilesPayload = { profiles: Profile[]; defaultProfile: string; defaultModel: string };
export const getProfiles = () => invoke<ProfilesPayload>("get_profiles");
export const saveProfiles = (payload: ProfilesPayload) =>
  invoke<void>("save_profiles", { profiles: payload.profiles, defaultProfile: payload.defaultProfile, defaultModel: payload.defaultModel });

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
