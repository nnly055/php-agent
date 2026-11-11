import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// vitest 2.x 自带 vite 5 类型，其 test 字段增强不会作用于项目的 vite 6，
// 这里本地声明把 vitest 的 test 配置并入 vite 6 的 UserConfig（Task 11 起使用）。
declare module "vite" {
  interface UserConfig {
    test?: import("vitest/config").UserConfig["test"];
  }
}

export default defineConfig({
  plugins: [react()],
  clearScreen: false,
  server: { port: 1420, strictPort: true },
  envPrefix: ["VITE_", "TAURI_"],
  build: { target: "es2021", outDir: "dist" },
  test: { environment: "jsdom", globals: true, setupFiles: ["./src/test-setup.ts"] },
});