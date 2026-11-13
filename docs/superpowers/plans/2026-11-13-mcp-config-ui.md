# MCP 配置管理（桌面设置入口）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在桌面设置对话框中增加「MCP 配置」Tab，可视化管理全局（`~/.php-agent/mcp.json`）与项目（`<workspace>/.mcp.json`）两层 MCP server，项目整条覆盖同名全局条目，保存后重启项目生效。

**Architecture:** PHP `McpConfig` 增加全局层合并（McpPlugin 不动）；Tauri 壳新增 3 个读写文件的 invoke 命令；前端 SettingsDialog 加 Tab 切换与两类 server 表单（stdio/http）。无新 HTTP 端点、无热更新。

**Tech Stack:** PHP 8.1（零框架）、Rust + Tauri v2、React + zustand + vitest。

**Spec:** `docs/superpowers/specs/2026-11-13-mcp-config-ui-design.md`

## Global Constraints

- `.mcp.json` 文件格式与 Claude Code / Cursor 互通：`{"mcpServers": {名: {command,args,env} | {url,headers}, timeout?}}`；条目内**不含** name 键（name 是 map 键）。
- 错误语义：文件缺失 = 静默空；JSON 损坏 = 抛异常/返回 Err（消息含文件路径）；单条目非法 = 收进 errors 不抛。
- 合并语义：项目条目**整条覆盖**同名全局条目；项目非法条目同名覆盖全局合法条目时，errors 胜出（该名无 server），不回落全局。
- timeout 默认 30 秒，显式值须 ≥ 1。
- UI 文案中文；不新增 npm/cargo/composer 依赖；不改动 MCP transport 与连接逻辑。

## Review Focus

1. **全局 JSON 损坏**：异常消息必须能区分来自 `~/.php-agent/mcp.json` 还是项目 `.mcp.json` → Task 1 测试 `testCorruptGlobalThrows`。
2. **同名覆盖边界**：项目非法条目覆盖全局合法条目 → errors 收集且 servers 无该名（不回落全局）→ Task 1 测试 `testInvalidProjectEntryShadowsValidGlobal`。
3. **文件里已有非法条目**（command/url 都有或都没有）：UI 须能回填显示（kind 取 command 非空为 stdio），保存时校验拦截而非崩溃 → Task 4 测试覆盖。
4. **env/headers 行解析**：无分隔符的行丢弃、`KEY=`（空值）保留、空行忽略 → Task 4 保存测试断言。
5. **无打开项目**：项目区置灰提示，保存仅写全局 → Task 4 测试 `saveMcpProject 不被调用`。

---

### Task 1: PHP — McpConfig 全局层

**Files:**
- Modify: `src/Mcp/McpConfig.php`
- Test: `tests/McpConfigTest.php`

**Interfaces:**
- Consumes: 现有 `McpConfig::load(string $workspace): array{servers: array<string, McpServerSpec>, errors: array<string, string>}`（McpPlugin 调用，签名保持兼容）。
- Produces:
  - `McpConfig::globalPath(): string` — 返回 `~/.php-agent/mcp.json` 绝对路径（HOME → USERPROFILE → sys_get_temp_dir() 兜底）。
  - `McpConfig::load(string $workspace, ?string $globalFile = null)` — `$globalFile` 仅测试注入用，null 时用 `globalPath()`。返回结构不变。

- [ ] **Step 1: 写失败测试**（追加到 `tests/McpConfigTest.php`，复用现有 setUp/tearDown 与 `write()` 助手；全局文件用新临时路径写入并在 tearDown 清理）

```php
public function testGlobalFileLoaded(): void {
    $global = $this->workspace . '-global.json';
    file_put_contents($global, json_encode(['mcpServers' => ['g' => ['command' => 'php']]]));
    $loaded = McpConfig::load($this->workspace, $global);
    self::assertSame([], $loaded['errors']);
    self::assertSame('php', $loaded['servers']['g']->config['command']);
    @unlink($global);
}

public function testProjectOverridesSameNameGlobal(): void {
    $global = $this->workspace . '-global.json';
    file_put_contents($global, json_encode(['mcpServers' => ['fs' => ['command' => 'old', 'args' => ['a']]]]));
    $this->write(json_encode(['mcpServers' => ['fs' => ['command' => 'new']]]));
    $spec = McpConfig::load($this->workspace, $global)['servers']['fs'];
    self::assertSame('new', $spec->config['command'], '整条覆盖，不保留全局 args');
    self::assertSame([], $spec->config['args']);
    @unlink($global);
}

public function testGlobalAndProjectMerged(): void {
    $global = $this->workspace . '-global.json';
    file_put_contents($global, json_encode(['mcpServers' => ['g' => ['command' => 'php']]]));
    $this->write(json_encode(['mcpServers' => ['p' => ['url' => 'https://a']]]));
    $loaded = McpConfig::load($this->workspace, $global);
    self::assertSame(['g', 'p'], array_keys($loaded['servers']));
    @unlink($global);
}

public function testCorruptGlobalThrows(): void {
    $global = $this->workspace . '-global.json';
    file_put_contents($global, '{ not json');
    try {
        McpConfig::load($this->workspace, $global);
        self::fail('全局 JSON 损坏必须抛异常');
    } catch (\RuntimeException $e) {
        self::assertStringContainsString($global, $e->getMessage(), '消息须标注来源文件');
    }
    @unlink($global);
}

public function testInvalidProjectEntryShadowsValidGlobal(): void {
    $global = $this->workspace . '-global.json';
    file_put_contents($global, json_encode(['mcpServers' => ['x' => ['command' => 'php']]]));
    $this->write(json_encode(['mcpServers' => ['x' => ['command' => 'a', 'url' => 'https://b']]]));
    $loaded = McpConfig::load($this->workspace, $global);
    self::assertArrayHasKey('x', $loaded['errors'], '项目非法条目进 errors');
    self::assertArrayNotHasKey('x', $loaded['servers'], '不回落到全局合法条目');
    @unlink($global);
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd /Users/mac/project/php-agent && vendor/bin/phpunit tests/McpConfigTest.php`
Expected: FAIL（`globalPath()`/`$globalFile` 参数不存在，或全局条目不生效）

- [ ] **Step 3: 实现**（`src/Mcp/McpConfig.php`）

- 新增 `public static function globalPath(): string`：`getenv('HOME')` → `getenv('USERPROFILE')` → `sys_get_temp_dir()`，拼 `.php-agent/mcp.json`。
- 现有 load 主体重构为 `private static function parseFile(string $file): array{servers, errors}`（逻辑不变，损坏消息用实际 `$file` 路径）。
- `load(string $workspace, ?string $globalFile = null)`：先 merge `parseFile($globalFile ?? globalPath())`（文件缺失静默），再 merge `parseFile($workspace . '/.mcp.json')`；merge 规则——spec 条目 `unset($errors[$name]); $servers[$name] = $spec;`，error 条目 `unset($servers[$name]); $errors[$name] = $err;`。

- [ ] **Step 4: 跑测试确认通过**

Run: `cd /Users/mac/project/php-agent && vendor/bin/phpunit tests/McpConfigTest.php && composer test`
Expected: PASS（全量 PHP 测试无回归）

- [ ] **Step 5: Commit**

```bash
cd /Users/mac/project/php-agent
git add src/Mcp/McpConfig.php tests/McpConfigTest.php
git commit -m "feat: McpConfig 支持全局 ~/.php-agent/mcp.json，项目同名整条覆盖"
```

---

### Task 2: Rust — get_mcp / save_mcp_global / save_mcp_project

**Files:**
- Modify: `desktop/src-tauri/src/lib.rs`（commands 区约 L497-516 附近追加，`mod tests` 在 L607）
- Modify: `desktop/src-tauri/src/lib.rs` `invoke_handler` 列表（L577-583）

**Interfaces:**
- Consumes: 现有 `home_dir()`（L26）、`PersistedState`/`load_state()`/`save_state_full()` 模式、`mod tests` 现有风格。
- Produces（Tauri 命令，前端 invoke 名与参数键）:
  - `get_mcp(workspace: Option<String>) -> Result<serde_json::Value, String>` → `{"global": [entry…], "project": [entry…] | null}`
  - `save_mcp_global(servers: Vec<McpServerEntry>) -> Result<(), String>`
  - `save_mcp_project(workspace: String, servers: Vec<McpServerEntry>) -> Result<(), String>`
  - 结构体 `McpServerEntry { name, command, args, env, url, headers, timeout }`（serde camelCase；见 Step 3）
  - 内部助手 `validate_mcp(&[McpServerEntry]) -> Result<(), String>`、`parse_mcp_file(&Path) -> Result<Vec<McpServerEntry>, String>`、`write_mcp_file(&Path, &[McpServerEntry]) -> Result<(), String>`

- [ ] **Step 1: 写失败测试**（追加到 `lib.rs` 现有 `mod tests`）

```rust
fn entry(name: &str, command: &str) -> McpServerEntry {
    McpServerEntry { name: name.into(), command: command.into(), args: vec![], env: Default::default(),
        url: String::new(), headers: Default::default(), timeout: None }
}

#[test]
fn validate_mcp_rejects_bad_entries() {
    assert!(validate_mcp(&[entry("a", "php")]).is_ok());
    assert!(validate_mcp(&[entry("", "php")]).is_err(), "名称空");
    assert!(validate_mcp(&[entry("a", "php"), entry("a", "php")]).is_err(), "名称重复");
    let both = McpServerEntry { url: "https://a".into(), ..entry("b", "php") };
    assert!(validate_mcp(&[both]).is_err(), "command/url 二选一");
    assert!(validate_mcp(&[entry("b", "")]).is_err(), "两者皆空");
    let bad_timeout = McpServerEntry { timeout: Some(0.5), ..entry("c", "php") };
    assert!(validate_mcp(&[bad_timeout]).is_err(), "timeout < 1");
}

#[test]
fn mcp_file_round_trip_and_php_compat() {
    let dir = std::env::temp_dir().join(format!("php-agent-mcp-rs-{}", std::process::id()));
    std::fs::create_dir_all(&dir).unwrap();
    let path = dir.join("mcp.json");
    let entries = vec![McpServerEntry { args: vec!["-y".into()], env: [("K".into(), "v".into())].into(),
        timeout: Some(5.0), ..entry("fs", "npx") }];
    write_mcp_file(&path, &entries).unwrap();
    let text = std::fs::read_to_string(&path).unwrap();
    let v: serde_json::Value = serde_json::from_str(&text).unwrap();
    let item = &v["mcpServers"]["fs"];
    assert_eq!(item["command"], "npx");
    assert_eq!(item["args"][0], "-y");
    assert_eq!(item["env"]["K"], "v");
    assert_eq!(item["timeout"], 5.0);
    assert!(item.get("name").is_none(), "文件条目不含 name 键");
    assert!(item.get("url").is_none(), "空 url 不落盘");
    // PHP 兼容格式回读：name 从 map 键注入
    let parsed = parse_mcp_file(&path).unwrap();
    assert_eq!(parsed[0].name, "fs");
    assert_eq!(parsed[0].args, vec!["-y".to_string()]);
    std::fs::remove_file(&path).unwrap();
    std::fs::remove_dir(&dir).unwrap();
}

#[test]
fn parse_mcp_file_errors() {
    let dir = std::env::temp_dir().join(format!("php-agent-mcp-rs-e-{}", std::process::id()));
    std::fs::create_dir_all(&dir).unwrap();
    let path = dir.join("mcp.json");
    assert_eq!(parse_mcp_file(&path).unwrap(), vec![], "缺失静默空");
    std::fs::write(&path, "{ nope").unwrap();
    assert!(parse_mcp_file(&path).is_err(), "损坏报错");
    std::fs::remove_file(&path).unwrap();
    std::fs::remove_dir(&dir).unwrap();
}
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd /Users/mac/project/php-agent/desktop/src-tauri && cargo test mcp`
Expected: 编译 FAIL（`McpServerEntry`/`validate_mcp` 不存在）

- [ ] **Step 3: 实现**（`lib.rs`）

- 结构体 `McpServerEntry`，derive `Debug, Clone, PartialEq, Serialize, Deserialize` + `#[serde(rename_all = "camelCase")]`：`name: String`（`#[serde(default)]`，**不加** skip_serializing——get_mcp 返回靠它带给前端）、`command: String`（default + `skip_serializing_if = "String::is_empty"`）、`args: Vec<String>`（default + skip if empty）、`env: BTreeMap<String, String>`（同上）、`url: String`（同 command）、`headers: BTreeMap<String, String>`（同 args）、`timeout: Option<f64>`（default + `skip_serializing_if = "Option::is_none"`）。
- `fn global_mcp_path() -> PathBuf { PathBuf::from(home_dir()).join(".php-agent").join("mcp.json") }`
- `parse_mcp_file`：缺失 → `Ok(vec![])`；读文本 `serde_json::from_str::<serde_json::Value>` 失败 → `Err("MCP 配置 {path} 不是合法 JSON")`；取 `v["mcpServers"]` 为对象，逐键 `from_value::<McpServerEntry>` 失败 → `Err`（消息含 server 名）；成功则注入 `name = map 键`。
- `write_mcp_file`：`validate_mcp`；逐条 `serde_json::to_value(entry)` 得 Value 后 `as_object_mut().remove("name")`（文件条目不含 name 键），组成 `json!({"mcpServers": map})`（BTreeMap 保序），`create_dir_all(parent)` 后写入。
- `validate_mcp`：名称空 / 重复 → Err；`command` 与 `url` 同时非空或同时为空 → Err（消息中文，如「server 'a'：command 与 url 必须二选一」）；`timeout` 若 Some 且 `< 1.0` → Err。
- 三个 `#[tauri::command]`：
  - `get_mcp`：global 恒解析；`workspace` 为 None 或无 `.mcp.json` 时 `project: serde_json::Value::Null`，否则解析 project（失败 Err）。返回 `json!({"global": global, "project": project})`。
  - `save_mcp_global`：`write_mcp_file(&global_mcp_path(), &servers)`。
  - `save_mcp_project`：`write_mcp_file(&PathBuf::from(workspace).join(".mcp.json"), &servers)`。
- `invoke_handler` 追加 `get_mcp, save_mcp_global, save_mcp_project`。

- [ ] **Step 4: 跑测试确认通过**

Run: `cd /Users/mac/project/php-agent/desktop/src-tauri && cargo test`
Expected: PASS（含 sidecar.rs 既有测试）

- [ ] **Step 5: Commit**

```bash
cd /Users/mac/project/php-agent
git add desktop/src-tauri/src/lib.rs
git commit -m "feat: Tauri 新增 get_mcp/save_mcp_global/save_mcp_project 命令"
```

---

### Task 3: 前端 — tauri.ts API 与 App 布线

**Files:**
- Modify: `desktop/src/lib/tauri.ts`
- Modify: `desktop/src/App.tsx:210-212`（SettingsDialog props）

**Interfaces:**
- Consumes: Task 2 的 Tauri 命令名与 serde 序列化（camelCase）。
- Produces（Task 4 使用）:

```ts
export type McpServer = {
  name: string;
  command: string; args: string[]; env: Record<string, string>;
  url: string; headers: Record<string, string>;
  timeout?: number | null;
};
export type McpPayload = { global: McpServer[]; project: McpServer[] | null };
export const getMcp = (workspace?: string | null) =>
  invoke<McpPayload>("get_mcp", { workspace: workspace ?? null });
export const saveMcpGlobal = (servers: McpServer[]) => invoke<void>("save_mcp_global", { servers });
export const saveMcpProject = (workspace: string, servers: McpServer[]) =>
  invoke<void>("save_mcp_project", { workspace, servers });
```

- App.tsx 给 SettingsDialog 增加 prop：`activeWorkspace={activeTab?.path ?? null}`（`ProjectTab.path` 即 workspace 绝对路径，L23）。

- [ ] **Step 1: 在 `tauri.ts` L26 之后追加上述类型与三个函数**（含注释：camelCase 与 Rust serde 对齐；timeout null = 默认 30 秒）。
- [ ] **Step 2: `App.tsx` L210-212 的 SettingsDialog 加 `activeWorkspace` prop**。
- [ ] **Step 3: 类型检查通过**

Run: `cd /Users/mac/project/php-agent/desktop && npx tsc -b`
Expected: 无 SettingsDialog 相关报错（此时 SettingsDialog 尚未消费 activeWorkspace，prop 传了没人用；TS 不会报 unused prop）

- [ ] **Step 4: Commit**

```bash
cd /Users/mac/project/php-agent
git add desktop/src/lib/tauri.ts desktop/src/App.tsx
git commit -m "feat: 前端 tauri API 增加 MCP 读写（getMcp/saveMcpGlobal/saveMcpProject）"
```

---

### Task 4: 前端 — SettingsDialog Tab 化 + MCP 面板

**Files:**
- Modify: `desktop/src/components/settings/SettingsDialog.tsx`
- Test: `desktop/src/components/settings/SettingsDialog.test.tsx`

**Interfaces:**
- Consumes: Task 3 的 `getMcp/saveMcpGlobal/saveMcpProject/McpServer/McpPayload`；现有 `useUiStore`（settingsOpen）、`Dialog/Button/Input/Textarea` 组件。
- Produces: SettingsDialog props 变为 `{ testUrl: string | null; activeWorkspace: string | null }`。

- [ ] **Step 1: 写失败测试**（`SettingsDialog.test.tsx`）

vi.mock 的 tauri 模块追加 mock（保留现有键）：

```ts
getMcp: vi.fn().mockResolvedValue({
  global: [
    { name: "fs", command: "npx", args: ["-y"], env: { K: "v" }, url: "", headers: {}, timeout: 5 },
    // Review Focus 3：文件里已有非法条目（command/url 皆有）也须回填显示不崩，kind 取 command 非空
    { name: "both", command: "x", args: [], env: {}, url: "https://a", headers: {}, timeout: null },
  ],
  project: [{ name: "remote", command: "", args: [], env: {}, url: "https://mcp.example.com", headers: { Authorization: "Bearer x" }, timeout: null }],
}),
saveMcpGlobal: vi.fn().mockResolvedValue(undefined),
saveMcpProject: vi.fn().mockResolvedValue(undefined),
```

新 describe 块（render 时传 `activeWorkspace="/tmp/ws"`）：

```ts
it("Tab 切换显示 MCP 配置并回填全局/项目条目", async () => {
  // 点「MCP」tab 按钮 → 全局区名称输入框回填 "fs" 与 "both"（后者同时显示 command 与 url，kind 为 stdio）、
  // 项目区 "remote"；args textarea 显示 "-y"；env textarea 显示 "K=v"；
  // headers textarea 显示 "Authorization: Bearer x"；timeout 输入框值 "5"；timeout 为 null 时空串
});

it("MCP 保存：解析文本字段并调用 saveMcpGlobal + saveMcpProject", async () => {
  // 点「MCP」→ 在全局区新增一个条目：名称 "g2"、类型 http、url "https://x"、headersText 输入 "A=1\nbadline\nB="
  // → 点保存 → saveMcpGlobal 收到 g2.headers 为 { A: "1", B: "" }（"badline" 无分隔符被丢弃、B= 空值保留）
  // 且 project 非空 → saveMcpProject("/tmp/ws", …) 被调用一次；结果提示含「重启项目后生效」
});
```

```ts
it("无打开项目：项目区置灰提示，保存仅调用 saveMcpGlobal", async () => {
  // activeWorkspace={null} → 出现「未打开项目」提示；保存 → saveMcpProject 未被调用
});

it("校验拦截：同名或 command/url 皆空时保存报错", async () => {
  // 两个同名条目 → 点保存 → saveMcpGlobal 未被调用，结果区显示校验消息
});
```

- [ ] **Step 2: 跑测试确认失败**

Run: `cd /Users/mac/project/php-agent/desktop && npm test -- SettingsDialog`
Expected: FAIL（找不到「MCP」tab）

- [ ] **Step 3: 实现 SettingsDialog**

- 组件签名加 `activeWorkspace`；内部状态：`const [tab, setTab] = useState<"profiles" | "mcp">("profiles")`，`onOpenChange` 打开时重置为 profiles。
- 对话框头部（DialogHeader 之后）渲染 tab 切换行：两个按钮「平台」「MCP」，选中态用现有样式类（如 `bg-surface`/`border-border` 组合，参考 ProjectTabs 的样式写法）。
- tab === "profiles" 渲染现有平台目录 JSX 原样搬移（平台管理逻辑零改动，仅包进条件分支；result 消息两 tab 共用底部区域）。
- tab === "mcp" 渲染 MCP 面板：
  - 面板内部草稿类型（不导出）：

    ```ts
    type McpDraftServer = {
      name: string; kind: "stdio" | "http";
      command: string; argsText: string; envText: string;   // argsText/envText: 每行一条
      url: string; headersText: string;                      // headersText: "K: V" 每行一条
      timeoutText: string;                                   // 空 = 默认
    };
    ```
  - 回填（首次进入 MCP tab 时 `getMcp(activeWorkspace)`，loading 态；失败时显示错误消息）：entry → draft 的 kind 取 `command` 非空为 stdio 否则 http（容忍 Review Focus 3 的非法条目回填显示）；args 数组 → `join("\n")`；env/headers record → `K=V` / `K: V` 每行；timeout → `String(t)`，null/缺省 → ""。
  - 布局：`全局 MCP`（说明行「对所有项目生效」）与 `当前项目 MCP`（说明行「覆盖同名全局条目」）两个区块，各自 server 卡片列表 + 「添加 server」按钮；卡片字段——名称 Input、类型 select（stdio/http，样式复用现有 provider select 的类）、stdio→command Input + argsText/envText Textarea（placeholder 示例 `npx`、`-y server`、`KEY=VALUE`），http→url Input + headersText Textarea，timeout Input（placeholder「30（默认）」）+ 删除按钮；`activeWorkspace` 为 null 时项目区整体置灰（opacity + disabled）并显示「未打开项目，仅可编辑全局配置」。
  - 保存流程：draft → McpServer 解析（args/env/headers 逐行解析：trim 后空行忽略；env 以**第一个** `=` 分割、headers 以**第一个** `:` 分割，无分隔符行丢弃，值可为空串；timeoutText trim 空串 → null，非数字或 <1 → 校验错误「timeout 须为 ≥ 1 的秒数」）；校验——名称非空且两区各自唯一、stdio→command 非空 / http→url 非空（消息中文）；校验失败显示在 result 区不调用 invoke。通过后 `saveMcpGlobal(globalServers)`，`activeWorkspace` 非空时再 `saveMcpProject(activeWorkspace, projectServers)`；两者成功 → result 显示「已保存；运行中的项目重启后生效」并保持对话框打开（用户可能还要改平台 tab）。
  - 测试连接按钮仅在 profiles tab 显示（MCP tab 无此按钮）。
- [ ] **Step 4: 跑测试确认通过**

Run: `cd /Users/mac/project/php-agent/desktop && npm test`
Expected: PASS（既有 SettingsDialog 平台用例零回归 + 新 MCP 用例）

- [ ] **Step 5: Commit**

```bash
cd /Users/mac/project/php-agent
git add desktop/src/components/settings/SettingsDialog.tsx desktop/src/components/settings/SettingsDialog.test.tsx
git commit -m "feat: 设置对话框增加 MCP 配置 Tab（全局 + 项目两层，重启生效）"
```

---

## 验证收尾（最后一个任务后执行）

- [ ] 全量回归：`composer test`（根目录）+ `cd desktop && npm test` + `cd desktop/src-tauri && cargo test`
- [ ] 手工冒烟（可选）：`cd desktop && npm run tauri dev`，齿轮 → MCP tab → 添加一条全局 stdio server → 确认 `~/.php-agent/mcp.json` 内容与 `{"mcpServers": {…}}` 格式一致
- [ ] CHANGELOG.md 追加条目（跟随现有格式）
