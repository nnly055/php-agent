# 多平台 × 多模型 × 对话内切换设计（profiles + per-turn client）

日期：2026-11-12
状态：已确认（用户批准方案 A）
关联文档：`docs/superpowers/specs/2026-11-12-desktop-config-design.md`

## 背景与目标

现状：单一全局配置（provider/apiKey/baseUrl/model），sidecar 启动时经 env 注入固定一个
LLM client（`AgentLoop` 持 readonly `$client`），切换必须重启进程。

目标：

- **多平台**：每平台独立的 name / provider 类型（openai 兼容 / anthropic / fake）/
  baseUrl / apiKey
- **每平台多模型**：自由编辑的模型字符串列表
- **对话内切换**：聊天输入区选择「平台 ▸ 模型」，**下一条消息即用新模型**，
  会话历史无缝延续（归一化消息格式本就 provider 无关）
- **按会话记忆模型**：每个对话持久化自己的 profile/model 选择（切走再切回不丢）；
  新对话默认用全局默认档
- **保存即生效**：设置保存后 HTTP 热更新运行中的 sidecar，加新平台/改 key 都无需重启

本轮不做（明确排除）：每项目独立的 profiles 覆盖；平台连通性后台巡检。

## 关键决策记录

| 决策点 | 选择 | 理由 |
|---|---|---|
| 切换粒度 | per-turn：`/agent` 请求携带 `profile_id`/`model` | 服务端无可变选择状态；天然支持每轮不同；历史照常走 SessionStore |
| 凭据下发 | spawn 注入默认档（env，向后兼容）+ `POST /config/profiles` 热更新全集 | 改 key/加平台无需重启；sidecar 重启后凭据仍在（env 兜底默认档） |
| 作用域 | profiles 目录全局一份；**模型选择按会话持久化**（`SessionMeta.profileId/model`，随 `/agent` 每轮落盘） | API key 是机器级心智；「哪个对话用哪个模型」是对话自身属性，切走切回应保持 |
| CLI 兼容 | `bin/agent` 单 provider env 行为完全不变 | CLI 不经过 AgentServer/Registry |
| 持久化 | desktop.json：`config` → `profiles[]` + `defaultProfile` + `defaultModel`；旧 config 迁移为首档 | 单文件惯例；serde default 向后兼容 |
| 客户端实例 | Registry 按 profileId+model 惰性构造并缓存（`Config::fromArray` 临时实例 → 现成 client 类） | 不与 AgentKernel 单实例缓存混用（键不同） |
| 模型列表 | 每平台自由编辑字符串数组 | 不做远端模型发现（各协议接口不一，YAGNI） |
| key 安全 | 多把 key 同一 0600 文件；`GET /config/profiles` 回传 key（桌面本机单用户，设置页需回显编辑）；不入日志/审计 | 沿用 desktop-config 的安全模型 |

## 详细设计

### 1. PHP

**ProfileRegistry**（新增 `src/Server/ProfileRegistry.php`）：

```php
final class ProfileRegistry
{
    /** @param array<int, array{id:string,name:string,provider:string,baseUrl:string,apiKey:string,models:array<int,string>}> $profiles */
    public function setProfiles(array $profiles, string $defaultProfileId): void;
    /** @return array{profiles:array,defaultProfile:string} */
    public function profiles(): array;
    /** per-turn 解析：null → 默认档；model 空 → profile 首个模型 */
    public function resolve(?string $profileId, ?string $model): LLMClient;
    /** 当前轮 client（Compactor 压缩跟随用；无显式 compactProvider 时） */
    public function activeClient(): ?LLMClient;
    public function setActiveClient(LLMClient $client): void;
}
```

- `resolve`：按 profileId 查 profile → `Config::fromArray([AGENT_PROVIDER/AGENT_API_KEY/
  AGENT_BASE_URL/AGENT_MODEL])`（model = 指定 model 或 profile.models[0]）→ 按类型
  实例化：openai → `OpenAICompatClient`、anthropic → `AnthropicClient`；
  **fake → 委托 kernel 的 fake 工厂**（`FakeLLMClient` 构造签名是脚本数组而非 Config，
  且离线语义本就不吃 baseUrl/key）；缓存键 `"{profileId}|{model}"`
- 校验：profileId 不存在 → `InvalidArgumentException`（路由层转 400）
- 构造初值：来自启动 env 的单配置 → 一个名为 `default` 的 profile（兼容未热更新的旧前端）

**AgentLoop**：`$client` 去 readonly，新增 `setClient(LLMClient $c): void`。
`bin/agent`（CLI）构造方式不变，行为零变化。

**AgentServer**：

- 构造函数追加 `ProfileRegistry $profiles`（bin/server 装配：env 单配置 → registry 初值）
- `POST /config/profiles`：body `{profiles, defaultProfile}` → 校验（id 唯一非空、
  provider ∈ openai|anthropic|fake、defaultProfile 存在于 profiles）→ `setProfiles` →
  `{ok:true}`；非法 400。**不落日志/审计**
- `GET /config/profiles`：回传 `{profiles, defaultProfile}`（**含 key**，设置页回显编辑）
- `/agent` 请求体新增可选 `profile_id`、`model`：handleAgent 先
  `registry->resolve(...)` → `loop->setClient(...)` → `registry->setActiveClient(...)`
  → 跑循环。未携带 → 该会话已存选择（meta），再退到默认档（行为与今天一致）
- **会话选择持久化**：`SessionMeta` 增加 `?string $profileId`、`?string $model`
  （`toArray/fromArray` 同步；旧数据 fromArray 缺省 null = 继承全局默认）。
  `SessionStore::save` 签名追加可选 `?array $selection = null`（`['profileId','model']`），
  四个实现（memory/json/sqlite3/redis）随 meta 落盘。`/agent` 每轮把本次选择传入 save；
  `GET /sessions` 的 meta、`GET /sessions/{id}` 响应（新增 `meta` 字段）都回传选择，
  供前端恢复下拉态

**压缩跟随**（plugins/compact）：Compactor 未显式配置 `compactProvider` 时改用
`registry->activeClient()`（压缩摘要与当前对话同一 provider/model）；显式配置
`AGENT_COMPACT_PROVIDER` 时维持现状（按 provider id 从 kernel 解析）。

### 2. Rust（desktop/src-tauri）

- `SidecarConfig` 替换为：

```rust
#[serde(rename_all = "camelCase", default)]
pub struct ProfileConfig { id, name, provider, api_key, base_url, models: Vec<String> }
// PersistedState:
pub profiles: Vec<ProfileConfig>, default_profile: Option<String>, default_model: Option<String>
```

（`config: Option<SidecarConfig>` 字段删除，读取时迁移）

- **迁移**：`load_state()` 若 `profiles` 空且 legacy `config` 存在且 provider 非空 →
  构造 `profiles = [ProfileConfig{ id:"default", name:provider, ..config }]` +
  `default_profile="default"` + `default_model=config.model`
- 命令：`get_config/save_config` → **`get_profiles` / `save_profiles(profiles,
  defaultProfile, defaultModel)`**（校验同 PHP 端；写 0600 文件）
- `open_session_inner` 注入**默认档**为 `AGENT_PROVIDER/API_KEY/BASE_URL/MODEL`
  （sidecar 重启后 PHP 端 env 初值 = 默认档；全集凭据由前端在项目就绪后
  `POST /config/profiles` 推送）

### 3. 前端（desktop/src）

- `lib/tauri.ts`：`Profile` 类型；`getProfiles()` / `saveProfiles(payload)`；
  `getConfig/saveConfig` 删除
- `lib/api.ts`：`pushProfiles(baseUrl, payload)`（POST /config/profiles，失败容忍——
  旧内核 404 时提示「该项目需重启后生效」）；`agentStream` 增可选 `profileId`/`model`
  参数并入请求体
- **useUiStore** 增全局 profiles 缓存：`profiles: Profile[]`、`defaultProfile`、
  `defaultModel`、`setProfiles(...)`（App 启动 + 保存后刷新）
- **SettingsDialog 重构**为平台管理：
  - 平台列表 + 「添加平台」；选中平台编辑：name、provider 下拉、baseUrl、apiKey
    （password）、models 多行编辑（一行一个）
  - 「测试连接」对当前编辑平台取值（沿用 /config/test）
  - 「保存」：`saveProfiles` → 对每个已连接 tab `pushProfiles` → 更新 useUiStore →
    关闭。**不再重启 sidecar**
- **Composer 切换器**：输入区上方一行「平台 ▸ 模型」下拉（数据 = useUiStore.profiles）。
  选择是**当前会话的状态**：存 projectStore `selection: {profileId, model}`，随
  `setActiveSession` 从会话 meta 恢复（新会话/无记录 → 全局默认）；用户改变后立即生效
  于下一条消息，并在该轮落盘到会话 meta
- **TitleBar**：徽章显示当前会话的选择 `name ▸ model`（无记录时显示默认档）；无任何
  profile 时橙色「未配置」逻辑保留（改为 profiles 为空判断）
- **ChatView/MessageBubble**：无改动（历史消息与 provider 无关）

### 4. 数据流总览

```
设置保存 ──saveProfiles──▶ desktop.json(0600)
         ├─pushProfiles×N──▶ 各运行中 sidecar（热更新，无重启）
         └─▶ useUiStore.profiles ──▶ Composer 下拉数据
对话发送 ──POST /agent {message, profile_id, model}──▶ registry.resolve
         ──▶ loop.setClient + setActiveClient ──▶ stream
```

## 错误处理

| 场景 | 行为 |
|---|---|
| profile_id 不存在（被删/会话存的旧选择失效） | `/agent` 400 `{error}`，前端显示为该轮错误消息；用户重新选择即可 |
| 会话 meta 无选择记录（旧会话） | 继承全局默认档 |
| model 不在列表 | 容忍（自由文本语义）；空则用 profile 首个模型 |
| pushProfiles 404（旧内核） | 前端提示「该项目需重启后生效」，不阻塞保存 |
| profiles 全部删除 | saveProfiles 校验拒绝（至少保留一个）；default 不在其中 → 400 |
| 切换后 key 失效/网络错误 | 走现有 SSE error 事件路径，换回原模型即可 |

## 测试计划

- **PHP**：Registry 解析/缓存/默认回落/非法 id；`/config/profiles` GET/POST 校验；
  `/agent` per-turn 切换（两个 FakeLLMClient 不同脚本，断言第二条消息由切换后 client
  回答）；CLI 现有测试守护不变；压缩跟随 activeClient
- **Rust**：legacy config → profiles 迁移；save/load roundtrip；默认档 env 注入；
  校验（default 必须存在等）
- **前端**：SettingsDialog 平台 CRUD + 保存链路（saveProfiles + pushProfiles，不调
  restart）；Composer 切换器选择与发送携带；store 默认回落
- **手工验收**：
  1. 设置里配 DeepSeek + GLM 两平台各 2 个模型 → 保存 → **无重启**，对话下拉立即可选
  2. 对话中途切换 → 下一条消息由新模型回答，历史延续
  3. 对话进行中新增第三平台 → 保存 → 无重启即可选用
  4. 会话 A 用 DeepSeek、会话 B 用 GLM → 切走再切回，各自下拉保持各自选择；
     重启 app 后打开会话同样恢复
  5. 重启 app → 新对话默认档自动生效；CLI `bin/agent` 行为不变
  6. key 不出现在日志/审计；desktop.json 权限 0600

## 验收标准

1. 全新状态：设置里添加两个平台 → 保存 → 打开项目 → 对话中下拉切换平台/模型 →
   消息由所选模型回答
2. 已运行 sidecar 下修改 apiKey/新增平台 → 保存 → 立即生效（无重启、无断线横幅）
3. CLI 不回归（现有 phpunit 全绿）
4. 旧 desktop.json（单 config）无损迁移
