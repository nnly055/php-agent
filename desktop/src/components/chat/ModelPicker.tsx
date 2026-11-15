import { ChevronDown, Settings2 } from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "../ui/dropdown-menu";
import { useProjectStore } from "../../store/useProjectStore";
import { useUiStore } from "../../store/useUiStore";
import type { Effort } from "../../lib/api";

// 思考强度档位展示名（wire 值仍为 off/low/medium/high/max）
const EFFORT_LABELS: ReadonlyArray<{ value: Effort; label: string }> = [
  { value: "off", label: "关" },
  { value: "low", label: "低" },
  { value: "medium", label: "中" },
  { value: "high", label: "高" },
  { value: "max", label: "最高" },
];

const PROVIDER_LABELS: Record<string, string> = {
  openai: "openai 兼容",
  anthropic: "anthropic 原生",
  fake: "fake（离线）",
};

/**
 * Composer 模型切换器：单胶囊按钮（当前模型名 + 箭头）→ 弹层按档分组列模型
 * + 思考强度五档 + 「管理模型…」。目录为空时不渲染（与旧 select 行为一致）。
 */
export function ModelPicker() {
  const store = useProjectStore();
  const streaming = store((s) => s.streaming);
  const selection = store((s) => s.selection);
  const setSelection = store((s) => s.setSelection);
  const profilesPayload = useUiStore((s) => s.profilesPayload);

  const profiles = profilesPayload?.profiles ?? [];
  if (profiles.length === 0) {
    // 空态入口：新用户目录为空时旧版整行不渲染，功能等于藏死了——给一个直达设置的占位按钮
    return (
      <button
        type="button"
        aria-label="选择模型"
        title="先在设置中配置模型平台"
        onClick={() => useUiStore.getState().setSettingsOpen(true)}
        className="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted transition-colors hover:bg-elevated hover:text-ink"
      >
        选择模型
        <ChevronDown size={13} className="shrink-0" />
      </button>
    );
  }

  const activeId = selection.profileId ?? profilesPayload?.defaultProfile ?? "";
  const activeProfile = profiles.find((p) => p.id === activeId);
  // 当前模型：会话选择 > 当前档首个；不在目录的名（恢复会话残留）照样显示（显示=实际）
  const currentModel = selection.model ?? activeProfile?.models[0]?.name ?? "";
  const platformTemp = activeId !== "" && !profiles.some((p) => p.id === activeId);
  const modelTemp = currentModel !== "" && !activeProfile?.models.some((m) => m.name === currentModel);

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          aria-label="选择模型"
          disabled={streaming}
          title="选择模型与思考强度"
          className="flex max-w-56 items-center gap-1 rounded-md px-2 py-1 text-xs text-muted transition-colors hover:bg-elevated hover:text-ink disabled:opacity-50"
        >
          <span className="truncate">{currentModel}</span>
          <ChevronDown size={13} className="shrink-0" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="max-h-96 w-64 overflow-y-auto">
        {(platformTemp || modelTemp) && (
          <>
            <DropdownMenuLabel className="text-warning">当前选择（不在目录中）</DropdownMenuLabel>
            <DropdownMenuRadioGroup
              value={currentModel}
              onValueChange={() => {}}
            >
              <DropdownMenuRadioItem value={currentModel} aria-current="true">{currentModel}</DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
            <DropdownMenuSeparator />
          </>
        )}
        {profiles.map((p) => (
          <div key={p.id}>
            <DropdownMenuLabel className="flex items-center justify-between">
              <span>{p.name || p.id}</span>
              <span className="text-xs font-normal text-muted">{PROVIDER_LABELS[p.provider] ?? p.provider}</span>
            </DropdownMenuLabel>
            <DropdownMenuRadioGroup
              value={selection.profileId === p.id ? (selection.model ?? "") : ""}
              onValueChange={(model) => {
                // 切档回落该档所选模型；强度保留当前会话选择（与 CLI /model 同语义）
                setSelection({ profileId: p.id, model, effort: selection.effort });
              }}
            >
              {p.models.map((m) => (
                <DropdownMenuRadioItem key={m.name} value={m.name} aria-current={m.name === currentModel ? "true" : "false"}>
                  {m.name}
                </DropdownMenuRadioItem>
              ))}
            </DropdownMenuRadioGroup>
            <DropdownMenuSeparator />
          </div>
        ))}
        <DropdownMenuLabel>思考强度</DropdownMenuLabel>
        <DropdownMenuRadioGroup
          value={selection.effort}
          onValueChange={(effort) => {
            // 强度只改 effort：profile/model 原样保留（与 CLI /effort 同语义）
            setSelection({ profileId: selection.profileId, model: selection.model, effort: effort as Effort });
          }}
        >
          {EFFORT_LABELS.map(({ value, label }) => (
            <DropdownMenuRadioItem key={value} value={value} aria-current={selection.effort === value ? "true" : "false"}>
              {label}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => useUiStore.getState().setSettingsOpen(true)}>
          <Settings2 size={14} />
          管理模型…
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
