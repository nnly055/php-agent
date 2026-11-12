import { create } from "zustand";

/** 全局 UI 状态（与项目无关）：设置对话框开合。项目数据一律走 useProjectStore。 */
type UiState = {
  settingsOpen: boolean;
  setSettingsOpen: (v: boolean) => void;
};

export const useUiStore = create<UiState>((set) => ({
  settingsOpen: false,
  setSettingsOpen: (v) => set({ settingsOpen: v }),
}));
