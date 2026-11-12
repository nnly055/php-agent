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
import { Plus, Trash2 } from "lucide-react";
import { getProfiles, saveProfiles, type Profile, type ProfilesPayload } from "../../lib/tauri";
import { pushProfiles, testConfig } from "../../lib/api";
import { useUiStore } from "../../store/useUiStore";

// provider 取值必须是小写枚举：后端严格校验，不做任何归一化
const PROVIDERS = [
  { id: "openai", label: "OpenAI 兼容（OpenAI / GLM / DeepSeek / Kimi…）" },
  { id: "anthropic", label: "Anthropic" },
  { id: "fake", label: "fake（离线演示）" },
];

// 新平台条目 id：时间戳 36 进制前缀 + 自增序号，避免同毫秒重复
let newIdSeq = 0;
const newProfileId = () => `p-${Date.now().toString(36)}-${newIdSeq++}`;

const emptyPayload = (): ProfilesPayload => ({ profiles: [], defaultProfile: "", defaultModel: "" });

/** 深拷贝目录：编辑态直接改 profiles 内对象，不能与全局缓存共享引用（模型条目逐个展开）。defaultModel 恒置空（服务端从不使用） */
const clonePayload = (p: ProfilesPayload): ProfilesPayload => ({
  profiles: p.profiles.map((x) => ({ ...x, models: x.models.map((m) => ({ ...m })) })),
  defaultProfile: p.defaultProfile,
  defaultModel: "",
});

export function SettingsDialog({ testUrl }: { testUrl: string | null }) {
  const open = useUiStore((s) => s.settingsOpen);
  const setOpen = useUiStore((s) => s.setSettingsOpen);
  // null = 目录未加载或为空 → 显示空态引导
  const [draft, setDraft] = useState<ProfilesPayload | null>(null);
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);

  // 打开时回填：优先 useUiStore 目录缓存（App 启动已拉取）；无缓存时 getProfiles 兜底
  useEffect(() => {
    if (!open) return;
    setResult(null);
    const cached = useUiStore.getState().profilesPayload;
    if (cached) {
      setDraft(clonePayload(cached));
    } else {
      getProfiles()
        .then((p) => setDraft(p.profiles.length > 0 ? clonePayload(p) : emptyPayload()))
        .catch(() => {});
    }
  }, [open]);

  function updateProfile(idx: number, patch: Partial<Profile>) {
    setDraft((d) =>
      d ? { ...d, profiles: d.profiles.map((p, i) => (i === idx ? { ...p, ...patch } : p)) } : d,
    );
  }

  function addPlatform() {
    const entry: Profile = { id: newProfileId(), name: "", provider: "openai", apiKey: "", baseUrl: "", models: [] };
    setDraft((d) => {
      const base = d ?? emptyPayload();
      return {
        ...base,
        profiles: [...base.profiles, entry],
        // 尚无默认档时让新条目顶上，保证 radio 始终有选中项
        defaultProfile: base.defaultProfile || entry.id,
      };
    });
  }

  function removePlatform(idx: number) {
    setDraft((d) => {
      if (!d || d.profiles.length <= 1) return d; // 至少保留一个平台
      const profiles = d.profiles.filter((_, i) => i !== idx);
      return {
        ...d,
        profiles,
        defaultProfile: profiles.some((p) => p.id === d.defaultProfile)
          ? d.defaultProfile
          : profiles[0].id,
      };
    });
  }

  async function runTest() {
    // 用默认档卡片（defaultProfile 对应条目，找不到回落首个）的值发起测试
    const target = draft?.profiles.find((p) => p.id === draft.defaultProfile) ?? draft?.profiles[0];
    if (!testUrl || !target) return;
    setTesting(true);
    setResult(null);
    try {
      const r = await testConfig(testUrl, {
        provider: target.provider,
        apiKey: target.apiKey,
        baseUrl: target.baseUrl,
        model: target.models[0]?.name ?? "",
      });
      setResult({ ok: r.ok, message: r.ok ? `连接成功（${r.latency_ms}ms）` : r.message });
    } catch (e: any) {
      // Tauri invoke 的 rejection 是字符串（无 .message 属性），必须按类型取，否则真实错误被吞
      setResult({ ok: false, message: (typeof e === "string" ? e : e?.message) ?? "测试请求失败" });
    } finally {
      setTesting(false);
    }
  }

  async function save() {
    if (!draft) return;
    // 丢弃未完成平台（无 id 或名称为空），名称为空的模型行一并过滤；至少保留一个完整平台
    const valid = draft.profiles.filter((p) => p.id && p.name.trim());
    if (valid.length === 0) {
      setResult({ ok: false, message: "至少需要保留一个完整平台（名称不能为空）" });
      return;
    }
    const payload: ProfilesPayload = {
      profiles: valid.map((p) => ({
        ...p,
        name: p.name.trim(),
        // contextTokens/maxTokens 在输入时已归一为 number|null，此处仅清洗名称并丢弃空名行
        models: p.models.map((m) => ({ ...m, name: m.name.trim() })).filter((m) => m.name),
      })),
      defaultProfile: valid.some((p) => p.id === draft.defaultProfile) ? draft.defaultProfile : valid[0].id,
      // defaultModel 恒传空串：服务端从不使用全局默认模型，模型回落 profile 首个（避免显示≠实际）
      defaultModel: "",
    };
    setSaving(true);
    setResult(null);
    try {
      await saveProfiles(payload); // 本地持久化
      useUiStore.getState().setProfilesPayload(payload); // 刷新全局目录缓存
      if (testUrl) {
        try {
          // 向运行中的项目热更新目录（不重启）；旧内核 404 也会走到这里
          await pushProfiles(testUrl, { profiles: payload.profiles, defaultProfile: payload.defaultProfile });
        } catch {
          // 保存本身已生效，仅热更新失败：留在对话框内提示「需重启后生效」
          setResult({ ok: false, message: "已保存到本地；向运行中项目推送失败，该项目需重启后生效" });
          return;
        }
      }
      setOpen(false);
    } catch (e: any) {
      // 同上：字符串 rejection 直接展示，避免所有失败都显示成笼统的「保存失败」
      setResult({ ok: false, message: (typeof e === "string" ? e : e?.message) ?? "保存失败" });
    } finally {
      setSaving(false);
    }
  }

  const canTest = !!testUrl && !!draft && draft.profiles.length > 0 && !testing && !saving;

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>平台管理</DialogTitle>
          <DialogDescription>全局平台目录，对所有项目生效；保存即热更新运行中的项目，无需重启。</DialogDescription>
        </DialogHeader>

        {!draft || draft.profiles.length === 0 ? (
          <div className="space-y-3 py-6 text-center">
            <p className="text-sm text-muted">尚未配置任何平台，请先添加一个平台。</p>
            <Button size="sm" variant="outline" onClick={addPlatform}>
              添加平台
            </Button>
          </div>
        ) : (
          <div className="space-y-3">
            {draft.profiles.map((p, i) => (
              <div key={p.id} className="space-y-2 rounded-md border border-border p-3">
                <div className="flex gap-2">
                  <label className="block flex-1 space-y-1">
                    <span className="text-sm text-muted">名称</span>
                    <Input
                      value={p.name}
                      placeholder="平台名称（如 DeepSeek）"
                      onChange={(e) => updateProfile(i, { name: e.target.value })}
                    />
                  </label>
                  <label className="block w-56 space-y-1">
                    <span className="text-sm text-muted">Provider</span>
                    <select
                      className="h-9 w-full rounded-md border border-border bg-surface px-2 text-sm text-ink"
                      value={p.provider}
                      onChange={(e) => updateProfile(i, { provider: e.target.value })}
                    >
                      {PROVIDERS.map((pv) => (
                        <option key={pv.id} value={pv.id}>
                          {pv.label}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
                <label className="block space-y-1">
                  <span className="text-sm text-muted">Base URL（空 = 该平台默认）</span>
                  <Input
                    value={p.baseUrl}
                    placeholder="https://api.openai.com/v1"
                    onChange={(e) => updateProfile(i, { baseUrl: e.target.value })}
                    className="font-mono"
                  />
                </label>
                <label className="block space-y-1">
                  <span className="text-sm text-muted">API Key</span>
                  <Input
                    type="password"
                    value={p.apiKey}
                    placeholder="sk-…"
                    onChange={(e) => updateProfile(i, { apiKey: e.target.value })}
                    className="font-mono"
                  />
                </label>
                {/* 模型动态条目列表：每行 = 名称 + 上下文 + 输出上限 + 删除；空名行保存时被过滤 */}
                <div className="space-y-1">
                  <span className="text-sm text-muted">模型（首个为默认）</span>
                  {p.models.map((m, mi) => (
                    <div key={mi} className="flex items-center gap-2">
                      <Input
                        value={m.name}
                        placeholder="模型名，如 deepseek-chat"
                        className="flex-1 font-mono"
                        onChange={(e) =>
                          updateProfile(i, {
                            models: p.models.map((mm, mmi) => (mmi === mi ? { ...mm, name: e.target.value } : mm)),
                          })
                        }
                      />
                      <Input
                        type="number"
                        min={1}
                        value={m.contextTokens ?? ""}
                        placeholder="上下文 128000"
                        className="w-28"
                        onChange={(e) =>
                          updateProfile(i, {
                            models: p.models.map((mm, mmi) =>
                              mmi === mi ? { ...mm, contextTokens: e.target.value === "" ? null : Number(e.target.value) } : mm,
                            ),
                          })
                        }
                      />
                      <Input
                        type="number"
                        min={1}
                        value={m.maxTokens ?? ""}
                        placeholder="输出默认"
                        className="w-28"
                        onChange={(e) =>
                          updateProfile(i, {
                            models: p.models.map((mm, mmi) =>
                              mmi === mi ? { ...mm, maxTokens: e.target.value === "" ? null : Number(e.target.value) } : mm,
                            ),
                          })
                        }
                      />
                      <Button
                        variant="outline"
                        size="icon"
                        aria-label="删除模型"
                        title="删除模型"
                        onClick={() => updateProfile(i, { models: p.models.filter((_, fmi) => fmi !== mi) })}
                      >
                        <Trash2 size={14} />
                      </Button>
                    </div>
                  ))}
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => updateProfile(i, { models: [...p.models, { name: "", contextTokens: null, maxTokens: null }] })}
                  >
                    <Plus size={14} /> 添加模型
                  </Button>
                </div>
                <div className="flex items-center justify-between">
                  <label className="flex items-center gap-1 text-sm text-ink">
                    <input
                      type="radio"
                      name="defaultProfile"
                      checked={draft.defaultProfile === p.id}
                      onChange={() => setDraft({ ...draft, defaultProfile: p.id })}
                    />
                    设为默认
                  </label>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={draft.profiles.length <= 1}
                    onClick={() => removePlatform(i)}
                  >
                    删除
                  </Button>
                </div>
              </div>
            ))}
            <Button variant="outline" size="sm" onClick={addPlatform}>
              添加平台
            </Button>
          </div>
        )}

        {result && (
          <p className={`text-xs ${result.ok ? "text-success" : "text-danger"}`}>
            {result.ok ? "✓ " : "✗ "}
            {result.message}
          </p>
        )}

        <DialogFooter className="mt-2 gap-2">
          <Button variant="outline" size="sm" disabled={!canTest} onClick={runTest}>
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
