import { create } from "zustand";
import type { ProfilesPayload } from "../lib/tauri";

/** 全局 UI 状态（与项目无关）：设置对话框开合、平台档目录缓存。项目数据一律走 useProjectStore。 */
type UiState = {
  settingsOpen: boolean;
  setSettingsOpen: (v: boolean) => void;
  profilesPayload: ProfilesPayload | null;
  setProfilesPayload: (p: ProfilesPayload | null) => void;
};

export const useUiStore = create<UiState>((set) => ({
  settingsOpen: false,
  setSettingsOpen: (v) => set({ settingsOpen: v }),
  // 全局目录缓存，App 启动与保存后刷新。
  profilesPayload: null as ProfilesPayload | null,
  setProfilesPayload: (p: ProfilesPayload | null) => set({ profilesPayload: p }),
}));
