import tailwindcssAnimate from "tailwindcss-animate";

/** @type {import('tailwindcss').Config} */
export default {
  darkMode: "class",
  content: ["./index.html", "./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        bg: "#0B0D12",
        surface: "#141821",
        elevated: "#1A1F2B",
        primary: { DEFAULT: "#8B8DF6", deep: "#5B5BD6" },
        info: "#38BDF8",
        success: "#4ADE80",
        warning: "#FBBF24",
        danger: "#FB7185",
        thinking: "#E879F9",
        ink: "#E8EAF2",
        muted: "#8A90A6",
        border: "#262B38",
      },
      fontFamily: {
        sans: ["Geist", "Inter", "system-ui", "PingFang SC", "sans-serif"],
        mono: ["JetBrains Mono", "ui-monospace", "Menlo", "monospace"],
      },
      keyframes: {
        blink: { "0%,100%": { opacity: "1" }, "50%": { opacity: "0" } },
        "fade-up": { from: { opacity: "0", transform: "translateY(6px)" }, to: { opacity: "1", transform: "translateY(0)" } },
      },
      animation: { blink: "blink 1s steps(1) infinite", "fade-up": "fade-up .25s ease both" },
    },
  },
  plugins: [tailwindcssAnimate],
};
