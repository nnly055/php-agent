import { invoke } from "@tauri-apps/api/core";
import { listen, type UnlistenFn } from "@tauri-apps/api/event";

export const getBackendUrl = () => invoke<string>("get_backend_url");
export const getWorkspace = () => invoke<string>("get_workspace");
export const pickWorkspace = () => invoke<string | null>("pick_workspace");
export const restartBackend = () => invoke<void>("restart_backend");

export type BackendEvent =
  | { kind: "ready"; port: number }
  | { kind: "disconnected" }
  | { kind: "error"; message: string };

export async function onBackendEvent(cb: (e: BackendEvent) => void): Promise<UnlistenFn[]> {
  const subs = await Promise.all([
    listen<{ port: number }>("backend://ready", (e) => cb({ kind: "ready", port: e.payload.port })),
    listen("backend://disconnected", () => cb({ kind: "disconnected" })),
    listen<{ message: string }>("backend://error", (e) => cb({ kind: "error", message: e.payload.message })),
  ]);
  return subs;
}