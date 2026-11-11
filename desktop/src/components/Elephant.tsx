export function Elephant({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 64 64" fill="none" className={className} aria-hidden>
      <ellipse cx="32" cy="34" rx="22" ry="18" fill="currentColor" opacity="0.18" />
      <path d="M14 30c0-10 8-18 18-18s18 8 18 18v6c0 3-2 5-5 5h-3l-2 8h-5l-1-6h-6l-1 6h-5l-2-8h-3c-3 0-5-2-5-5v-6z" fill="currentColor" opacity="0.55" />
      <circle cx="25" cy="28" r="2.4" fill="#0B0D12" />
      <path d="M44 34c4 1 7 4 7 8" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
      <text x="32" y="56" textAnchor="middle" fontSize="9" fontFamily="monospace" fill="currentColor">php</text>
    </svg>
  );
}