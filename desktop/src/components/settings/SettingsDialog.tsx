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
import { Textarea } from "../ui/textarea";
import { Plus, Trash2 } from "lucide-react";
import {
  getMcp,
  getProfiles,
  saveMcpGlobal,
  saveMcpProject,
  saveProfiles,
  type McpServer,
  type Profile,
  type ProfilesPayload,
} from "../../lib/tauri";
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

// ── MCP 配置（全局 + 项目两层） ─────────────────────────────

/** 编辑态条目：文件字段摊平成单行/多行文本，保存时再解析（textarea 受控值必须是文本，避免逐键重解析丢字） */
type McpDraftServer = {
  name: string;
  kind: "stdio" | "http";
  command: string;
  argsText: string;
  envText: string;
  url: string;
  headersText: string;
  timeoutText: string;
};

type McpDraft = { global: McpDraftServer[]; project: McpDraftServer[] | null };

/** 文件条目 → 编辑态：kind 取 command 非空为 stdio（文件里可能有 command/url 皆有的非法条目，回填显示不崩，保存时校验拦截） */
const toMcpDraft = (e: McpServer): McpDraftServer => ({
  name: e.name,
  kind: e.command ? "stdio" : "http",
  command: e.command,
  argsText: e.args.join("\n"),
  envText: Object.entries(e.env).map(([k, v]) => `${k}=${v}`).join("\n"),
  url: e.url,
  headersText: Object.entries(e.headers).map(([k, v]) => `${k}: ${v}`).join("\n"),
  timeoutText: e.timeout != null ? String(e.timeout) : "",
});

/** env/headers 多行文本 → record：空行忽略，无分隔符（或 key 为空）的行丢弃，key/value 去首尾空白（value 可为空串） */
const parsePairs = (text: string, sep: string): Record<string, string> => {
  const out: Record<string, string> = {};
  for (const line of text.split("\n")) {
    const t = line.trim();
    if (!t) continue;
    const i = t.indexOf(sep);
    if (i <= 0) continue;
    out[t.slice(0, i).trim()] = t.slice(i + sep.length).trim();
  }
  return out;
};

export function SettingsDialog({
  testUrl,
  activeWorkspace,
}: {
  testUrl: string | null;
  activeWorkspace?: string | null;
}) {
  const open = useUiStore((s) => s.settingsOpen);
  const setOpen = useUiStore((s) => s.setSettingsOpen);
  // null = 目录未加载或为空 → 显示空态引导
  const [draft, setDraft] = useState<ProfilesPayload | null>(null);
  const [testing, setTesting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; message: string } | null>(null);
  const [tab, setTab] = useState<"profiles" | "mcp">("profiles");
  // null = 未加载；mcpLoaded 防同一次打开内切 tab 重复拉取
  const [mcpDraft, setMcpDraft] = useState<McpDraft | null>(null);
  const [mcpLoaded, setMcpLoaded] = useState(false);

  // 打开时回填：优先 useUiStore 目录缓存（App 启动已拉取）；无缓存时 getProfiles 兜底。
  // 重置 tab 与 MCP 草稿：每次打开都从磁盘重读（.mcp.json 可能被外部编辑过）
  useEffect(() => {
    if (!open) return;
    setResult(null);
    setTab("profiles");
    setMcpDraft(null);
    setMcpLoaded(false);
    const cached = useUiStore.getState().profilesPayload;
    if (cached) {
      setDraft(clonePayload(cached));
    } else {
      getProfiles()
        .then((p) => setDraft(p.profiles.length > 0 ? clonePayload(p) : emptyPayload()))
        .catch(() => {});
    }
  }, [open]);

  // 首次切到 MCP tab 时拉取两层配置
  useEffect(() => {
    if (!open || tab !== "mcp" || mcpLoaded) return;
    getMcp(activeWorkspace ?? null)
      .then((p) => {
        setMcpDraft({
          global: p.global.map(toMcpDraft),
          project: p.project ? p.project.map(toMcpDraft) : null,
        });
        setMcpLoaded(true);
      })
      .catch((e: any) => {
        setMcpLoaded(true);
        setResult({ ok: false, message: (typeof e === "string" ? e : e?.message) ?? "MCP 配置读取失败" });
      });
  }, [open, tab, mcpLoaded, activeWorkspace]);

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
    // maxTokens 前置校验：与 PHP setProfiles（≥1024 显式报错）对齐，防止本地保存成功而
    // PHP 推送被拒的 split-brain（Rust 侧仅校验 >0，入口在此挡住）
    if (payload.profiles.some((p) => p.models.some((m) => m.maxTokens != null && m.maxTokens < 1024))) {
      setResult({ ok: false, message: "模型 maxTokens 不能低于 1024" });
      return;
    }
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

  // ── MCP 编辑 ──

  function updateMcp(section: "global" | "project", idx: number, patch: Partial<McpDraftServer>) {
    setMcpDraft((d) => {
      if (!d) return d;
      if (section === "project") {
        if (!d.project) return d;
        return { ...d, project: d.project.map((s, i) => (i === idx ? { ...s, ...patch } : s)) };
      }
      return { ...d, global: d.global.map((s, i) => (i === idx ? { ...s, ...patch } : s)) };
    });
  }

  function addMcpServer(section: "global" | "project") {
    const entry: McpDraftServer = {
      name: "", kind: "stdio", command: "", argsText: "", envText: "", url: "", headersText: "", timeoutText: "",
    };
    setMcpDraft((d) => {
      if (!d) return d;
      if (section === "project") {
        return d.project ? { ...d, project: [...d.project, entry] } : d;
      }
      return { ...d, global: [...d.global, entry] };
    });
  }

  function removeMcpServer(section: "global" | "project", idx: number) {
    setMcpDraft((d) => {
      if (!d) return d;
      if (section === "project") {
        return d.project ? { ...d, project: d.project.filter((_, i) => i !== idx) } : d;
      }
      return { ...d, global: d.global.filter((_, i) => i !== idx) };
    });
  }

  /** 编辑态 → 文件条目；非法返回中文错误消息（校验与 PHP/Rust 语义对齐：名称唯一、command/url 二选一、timeout ≥ 1） */
  function parseMcpSection(list: McpDraftServer[]): McpServer[] | string {
    const seen = new Set<string>();
    for (const s of list) {
      const name = s.name.trim();
      if (!name) return "MCP server 名称不能为空";
      if (seen.has(name)) return `MCP server 名称重复: ${name}`;
      seen.add(name);
      const hasCommand = !!s.command.trim();
      const hasUrl = !!s.url.trim();
      if (hasCommand === hasUrl) return `MCP server '${name}'：command 与 url 必须二选一`;
      if (s.timeoutText.trim() !== "") {
        const n = Number(s.timeoutText);
        if (!Number.isFinite(n) || n < 1) return `MCP server '${name}'：timeout 须为 ≥ 1 的秒数`;
      }
    }
    return list.map((s) => {
      const isStdio = s.kind === "stdio";
      return {
        name: s.name.trim(),
        command: isStdio ? s.command.trim() : "",
        args: isStdio ? s.argsText.split("\n").map((x) => x.trim()).filter(Boolean) : [],
        env: isStdio ? parsePairs(s.envText, "=") : {},
        url: !isStdio ? s.url.trim() : "",
        headers: !isStdio ? parsePairs(s.headersText, ":") : {},
        timeout: s.timeoutText.trim() === "" ? null : Number(s.timeoutText),
      };
    });
  }

  async function saveMcp() {
    if (!mcpDraft) return;
    const g = parseMcpSection(mcpDraft.global);
    if (typeof g === "string") {
      setResult({ ok: false, message: g });
      return;
    }
    // 有打开项目才解析/保存项目层（project 为 null = 项目区不可编辑）
    const p = mcpDraft.project ? parseMcpSection(mcpDraft.project) : null;
    if (typeof p === "string") {
      setResult({ ok: false, message: p });
      return;
    }
    setSaving(true);
    setResult(null);
    try {
      await saveMcpGlobal(g);
      if (mcpDraft.project) {
        await saveMcpProject(activeWorkspace!, p!);
      }
      setResult({ ok: true, message: "已保存；运行中的项目重启后生效" });
    } catch (e: any) {
      setResult({ ok: false, message: (typeof e === "string" ? e : e?.message) ?? "保存失败" });
    } finally {
      setSaving(false);
    }
  }

  const canTest = !!testUrl && !!draft && draft.profiles.length > 0 && !testing && !saving;

  /** 一层 server 卡片列表（全局区与项目区共用） */
  function renderMcpCards(list: McpDraftServer[] | null, section: "global" | "project") {
    if (!list || list.length === 0) {
      return <p className="text-xs text-muted">尚无 server，点击下方「添加 server」。</p>;
    }
    return (
      <>
        {list.map((s, i) => (
          <div key={`${section}-${i}`} className="space-y-2 rounded-md border border-border p-3">
            <div className="flex gap-2">
              <label className="block flex-1 space-y-1">
                <span className="text-sm text-muted">名称</span>
                <Input
                  value={s.name}
                  placeholder="server 名称（如 filesystem）"
                  onChange={(e) => updateMcp(section, i, { name: e.target.value })}
                />
              </label>
              <label className="block w-40 space-y-1">
                <span className="text-sm text-muted">类型</span>
                <select
                  className="h-9 w-full rounded-md border border-border bg-surface px-2 text-sm text-ink"
                  value={s.kind}
                  onChange={(e) => {
                    // 切换类型清空另一侧字段：kind 决定写入侧，避免隐藏侧残留数据触发「二选一」校验
                    const kind = e.target.value === "http" ? "http" : "stdio";
                    updateMcp(section, i,
                      kind === "http"
                        ? { kind, command: "", argsText: "", envText: "" }
                        : { kind, url: "", headersText: "" },
                    );
                  }}
                >
                  <option value="stdio">stdio（本地命令）</option>
                  <option value="http">http（远程 URL）</option>
                </select>
              </label>
            </div>
            {s.kind === "stdio" ? (
              <>
                <label className="block space-y-1">
                  <span className="text-sm text-muted">命令</span>
                  <Input
                    value={s.command}
                    placeholder="命令，如 npx"
                    className="font-mono"
                    onChange={(e) => updateMcp(section, i, { command: e.target.value })}
                  />
                </label>
                <label className="block space-y-1">
                  <span className="text-sm text-muted">参数（每行一个）</span>
                  <Textarea
                    value={s.argsText}
                    placeholder={"每行一个参数，如 -y server"}
                    rows={2}
                    className="font-mono"
                    onChange={(e) => updateMcp(section, i, { argsText: e.target.value })}
                  />
                </label>
                <label className="block space-y-1">
                  <span className="text-sm text-muted">环境变量（每行 KEY=VALUE）</span>
                  <Textarea
                    value={s.envText}
                    placeholder={"KEY=VALUE"}
                    rows={2}
                    className="font-mono"
                    onChange={(e) => updateMcp(section, i, { envText: e.target.value })}
                  />
                </label>
              </>
            ) : (
              <>
                <label className="block space-y-1">
                  <span className="text-sm text-muted">URL</span>
                  <Input
                    value={s.url}
                    placeholder="https://mcp.example.com/mcp"
                    className="font-mono"
                    onChange={(e) => updateMcp(section, i, { url: e.target.value })}
                  />
                </label>
                <label className="block space-y-1">
                  <span className="text-sm text-muted">请求头（每行 KEY: VALUE）</span>
                  <Textarea
                    value={s.headersText}
                    placeholder={"Authorization: Bearer x"}
                    rows={2}
                    className="font-mono"
                    onChange={(e) => updateMcp(section, i, { headersText: e.target.value })}
                  />
                </label>
              </>
            )}
            <div className="flex items-end gap-2">
              <label className="block w-40 space-y-1">
                <span className="text-sm text-muted">timeout（秒）</span>
                <Input
                  type="number"
                  min={1}
                  value={s.timeoutText}
                  placeholder="30（默认）"
                  onChange={(e) => updateMcp(section, i, { timeoutText: e.target.value })}
                />
              </label>
              <Button
                variant="outline"
                size="sm"
                onClick={() => removeMcpServer(section, i)}
              >
                删除
              </Button>
            </div>
          </div>
        ))}
      </>
    );
  }

  function addMcpButton(section: "global" | "project") {
    return (
      <Button variant="outline" size="sm" onClick={() => addMcpServer(section)}>
        <Plus size={14} /> 添加 server
      </Button>
    );
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{tab === "mcp" ? "MCP 配置" : "平台管理"}</DialogTitle>
          <DialogDescription>
            {tab === "mcp"
              ? "全局 MCP 对所有项目生效，项目配置覆盖同名全局条目；保存后重启项目生效。"
              : "全局平台目录，对所有项目生效；保存即热更新运行中的项目，无需重启。"}
          </DialogDescription>
        </DialogHeader>

        <div className="flex gap-1 border-b border-border pb-2">
          <Button
            variant={tab === "profiles" ? "secondary" : "ghost"}
            size="sm"
            onClick={() => setTab("profiles")}
          >
            平台
          </Button>
          <Button
            variant={tab === "mcp" ? "secondary" : "ghost"}
            size="sm"
            onClick={() => setTab("mcp")}
          >
            MCP
          </Button>
        </div>

        {tab === "profiles" && (!draft || draft.profiles.length === 0 ? (
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
                        onChange={(e) => {
                          // 归一化加固：非法/非正数置空，小数截断为整数（3.5 → 3）
                          const n = Number(e.target.value);
                          const v = Number.isFinite(n) && n > 0 ? Math.trunc(n) : null;
                          updateProfile(i, {
                            models: p.models.map((mm, mmi) =>
                              mmi === mi ? { ...mm, contextTokens: v } : mm,
                            ),
                          });
                        }}
                      />
                      <Input
                        type="number"
                        min={1024}
                        value={m.maxTokens ?? ""}
                        placeholder="输出 ≥1024"
                        className="w-28"
                        onChange={(e) => {
                          // 同上；下限 1024 由保存前置校验拦截（与后端 setProfiles/Config::maxTokens 对齐）
                          const n = Number(e.target.value);
                          const v = Number.isFinite(n) && n > 0 ? Math.trunc(n) : null;
                          updateProfile(i, {
                            models: p.models.map((mm, mmi) =>
                              mmi === mi ? { ...mm, maxTokens: v } : mm,
                            ),
                          });
                        }}
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
        ))}

        {tab === "mcp" && (
          <div className="space-y-4">
            <div className="space-y-2">
              <div className="flex items-baseline justify-between">
                <span className="text-sm font-medium text-ink">全局 MCP</span>
                <span className="text-xs text-muted">对所有项目生效</span>
              </div>
              {renderMcpCards(mcpDraft?.global ?? null, "global")}
              {addMcpButton("global")}
            </div>
            {activeWorkspace ? (
              <div className="space-y-2">
                <div className="flex items-baseline justify-between">
                  <span className="text-sm font-medium text-ink">当前项目 MCP</span>
                  <span className="text-xs text-muted">覆盖同名全局条目</span>
                </div>
                {renderMcpCards(mcpDraft?.project ?? null, "project")}
                {addMcpButton("project")}
              </div>
            ) : (
              <p className="text-sm text-muted">未打开项目，仅可编辑全局配置。</p>
            )}
          </div>
        )}

        {result && (
          <p className={`text-xs ${result.ok ? "text-success" : "text-danger"}`}>
            {result.ok ? "✓ " : "✗ "}
            {result.message}
          </p>
        )}

        <DialogFooter className="mt-2 gap-2">
          {tab === "profiles" && (
            <Button variant="outline" size="sm" disabled={!canTest} onClick={runTest}>
              {testing ? "测试中…" : "测试连接"}
            </Button>
          )}
          <Button
            size="sm"
            disabled={saving}
            onClick={() => (tab === "mcp" ? saveMcp() : save())}
          >
            {saving ? "保存中…" : "保存"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
