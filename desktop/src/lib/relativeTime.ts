/** 相对时间（会话列表展示）：刚刚 / n分 / n小时 / n天 / n周。now 参数仅供测试注入。 */
export function relativeTime(ts: number, now: number = Date.now()): string {
  const s = Math.max(0, Math.floor((now - ts) / 1000));
  if (s < 60) return "刚刚";
  if (s < 3600) return `${Math.floor(s / 60)}分`;
  if (s < 86400) return `${Math.floor(s / 3600)}小时`;
  if (s < 604800) return `${Math.floor(s / 86400)}天`;
  return `${Math.floor(s / 604800)}周`;
}
