import { useEffect, useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "../ui/dialog";
import { Button } from "../ui/button";
import { Input } from "../ui/input";
import { getConfig, saveConfig, type AgentConfig } from "../../lib/tauri";
import { testConfig } from "../../lib/api";
import { useUiStore } from "../../store/useUiStore";

const PROVIDERS = [
  {
    id: "openai",
    label: "OpenAI 兼容（OpenAI / GLM / DeepSeek / Kimi…）",
    urlPlaceholder: "https://api.openai.com/v1",
    modelPlaceholder: "gpt-4o / glm-4.7 / deepseek-chat…",
  },
  {
    id: "anthropic",
    label: "Anthropic",
    urlPlaceholder: "https://api.anthropic.com",
    modelPlaceholder: "claude-sonnet-4-5",
  },
  {
    id: "fake",
    label: "fake（离线演示）",
    urlPlaceholder: "无需 Base URL",
    modelPlaceholder: "fake-model",
  },
];

export function SettingsDialog({
  testUrl,
  onSaved,
}: {
  testUrl: string | null;
  onSaved: (cfg: AgentConfig) => Promise<void>;
}) {
  const open = useUiStore((s) => s.settingsOpen);
  const setOpen = useUiStore((s) => s.setSettingsOpen);
  const [cfg, setCfg] = useState<AgentConfig>({ provider: "openai", apiKey: "", baseUrl: "", model: "" });
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);

  // 打开时回填持久化配置；空 provider 回落 openai（get_config 未配置时返回空字段）
  useEffect(() => {
    if (!open) return;
    setResult(null);
    getConfig()
      .then((saved) => setCfg({ ...saved, provider: saved.provider || "openai" }))
      .catch(() => {});
  }, [open]);

  const meta = PROVIDERS.find((p) => p.id === cfg.provider) ?? PROVIDERS[0];

  async function runTest() {
    if (!testUrl) return;
    setTesting(true);
    setResult(null);
    try {
      const r = await testConfig(testUrl, cfg);
      setResult({ ok: r.ok, message: r.ok ? `连接成功（${r.latency_ms}ms）` : r.message });
    } catch (e: any) {
      // Tauri invoke 的 rejection 是字符串（无 .message 属性），必须按类型取，否则真实错误被吞
      setResult({ ok: false, message: (typeof e === "string" ? e : e?.message) ?? "测试请求失败" });
    } finally {
      setTesting(false);
    }
  }

  async function save() {
    setSaving(true);
    try {
      await saveConfig(cfg); // 持久化；重启各项目由 App 的 onSaved 编排
      await onSaved(cfg);
    } catch (e: any) {
      // 同上：字符串 rejection 直接展示，避免所有失败都显示成笼统的「保存失败」
      setResult({ ok: false, message: (typeof e === "string" ? e : e?.message) ?? "保存失败" });
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>API 配置</DialogTitle>
          <DialogDescription>全局配置，对所有项目生效；保存后运行中的项目会自动重启。</DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <label className="block space-y-1">
            <span className="text-sm text-muted">Provider</span>
            <select
              className="h-9 w-full rounded-md border border-border bg-surface px-2 text-sm text-ink"
              value={cfg.provider}
              onChange={(e) => setCfg({ ...cfg, provider: e.target.value })}
            >
              {PROVIDERS.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.label}
                </option>
              ))}
            </select>
          </label>
          <label className="block space-y-1">
            <span className="text-sm text-muted">Base URL（空 = 默认）</span>
            <Input
              value={cfg.baseUrl}
              placeholder={meta.urlPlaceholder}
              onChange={(e) => setCfg({ ...cfg, baseUrl: e.target.value })}
              className="font-mono"
            />
          </label>
          <label className="block space-y-1">
            <span className="text-sm text-muted">API Key</span>
            <Input
              type="password"
              value={cfg.apiKey}
              placeholder="sk-…"
              onChange={(e) => setCfg({ ...cfg, apiKey: e.target.value })}
              className="font-mono"
            />
          </label>
          <label className="block space-y-1">
            <span className="text-sm text-muted">Model</span>
            <Input
              value={cfg.model}
              placeholder={meta.modelPlaceholder}
              onChange={(e) => setCfg({ ...cfg, model: e.target.value })}
              className="font-mono"
            />
          </label>
          {result && (
            <p className={`text-xs ${result.ok ? "text-success" : "text-danger"}`}>
              {result.ok ? "✓ " : "✗ "}
              {result.message}
            </p>
          )}
        </div>
        <DialogFooter className="mt-2 gap-2">
          <Button variant="outline" size="sm" disabled={!testUrl || testing || saving} onClick={runTest}>
            {testing ? "测试中…" : "测试连接"}
          </Button>
          <Button size="sm" disabled={testing || saving} onClick={save}>
            {saving ? "保存中…" : "保存"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
