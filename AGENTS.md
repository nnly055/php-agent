# AGENTS.md — php-agent 项目约定

本文件是项目持久契约，每次会话自动加载。只放稳定不变的东西：
跨功能决策的"为什么"见 `docs/decisions.md`；功能工作稿在 `docs/superpowers/`（仅本地）。

## 项目速览

纯 PHP coding agent，零框架（curl+Fiber 流式 / ReactPHP 本地服务）。结构：

- `src/` — PHP 内核：Agent（循环）/ Kernel（装配、ProfileRegistry）/ LLM（双协议客户端）/
  Session（存储）/ Server（HTTP+SSE）/ Mcp / Tools / Hook / Command / Ui
- `plugins/` — 内置插件（compact / diff / example / mysql-store），各自 src/，独立 autoload
  （如 Compactor 在 `plugins/compact/src/`，不在 src/）
- `desktop/src/` — React 18 + zustand + Tailwind + Radix 原语；`desktop/src-tauri/` — Rust 壳（Tauri 2）
- `desktop/src-tauri/resources/php-agent/` — PHP 后端 bundle 副本，由 `desktop/scripts/bundle-backend.sh`
  组装；**改了 PHP 后端，桌面发布前须重新 bundle**
- PHP ≥8.1（CI 矩阵 8.1/8.2/8.3）；PHPUnit 10；夹具 `tests/fixtures/*/vendor` 必须入库

## 命令

```bash
composer test                    # PHP 套件
cd desktop && npm test           # 前端套件（vitest）
cd desktop && npm run tauri dev  # 桌面开发（自动拉起 PHP sidecar）
cargo test                       # Rust 测试仅本地（CI 无 webkit2gtk，不跑）
php bin/agent --fake             # 离线演示（无需 API key）
```

## 跨层硬契约

1. 归一化历史块对齐 Anthropic 块结构，snake_case 键：text / tool_use / tool_result / image /
   thinking / redacted_thinking（src/Agent/AgentLoop.php）
2. 同一校验规则在其存在的每个入口同下限：maxTokens≥1024 → FE SettingsDialog.tsx + Config.php +
   ProfileRegistry.php；models 非空 / baseUrl http(s) → FE + PHP + Rust lib.rs
3. 请求回退三级同构：请求携带 → 会话 meta → 默认值（profileId / model / effort 同形状，AgentServer.php）
4. selection 三键 {profileId, model, effort} 均可 null，CLI / 桌面 / Rebinder 同形状
   （SessionModelRebinder.php）
5. store save 用 array_key_exists 判定覆盖（显式 null 是权威），禁用 ??（Session/Store 四实现一致）
6. 新能力默认 off：off / 缺省 = 不发字段 = 现行为零变化（OpenAICompatClient reasoning_effort 映射）
7. 签名扩展加可选参数带默认值，既有调用点零变化（LLMClient::stream 第 4 参 $options = []）
8. 安全基线：配置文件原子写 + 0600（Rust lib.rs mcp.json；PHP JsonFileSessionStore 同目录 rename）；
   凭据端点 Origin 白名单（非法 403）；workspace 路径校验；服务仅绑 127.0.0.1
9. PHP 8.1 兼容：高版本常量/函数用 defined() / function_exists 防护
   （BaseSseClient.php CURLOPT_XFERINFOFUNCTION，CI 8.1 缺此常量）
10. 版本三处同步：Config::VERSION / desktop/package.json / src-tauri/tauri.conf.json
11. 测试 tearDown 清理 AGENT_WORKSPACE 等环境变量，防用例间污染（McpConfigTest.php）
12. 跨协议改动补 wire 矩阵回放测试：openai / anthropic 产历史互回放线上格式断言
    （tests/HttpTransportTest.php）
13. 零新依赖优先：复用仓库已有原语（Radix 组件、zustand store 模式）
14. compact：image 块按固定 1600 token/图估算（plugins/compact IMAGE_TOKEN_COST），base64 不按
    字符数计；压缩调用走默认 options（off），不传 effort

## bug 类（本仓库自定义，终审按此抓）

- **显示≠实际**：UI 显示的状态必须等于实际生效值（显示 off 就是 off）
- **静默丢失**：用户显式选择不得被重置/覆盖吞掉（effort-only 选择、发送前模型选择）
- **误导性错误**：底层错误不得包装成不相关报错（body 超限曾表现为"message 不能为空"）

## 质量门槛（merge 前终审清单）

- [ ] 测试全绿：composer test + cd desktop && npm test（Rust 改动加 cargo test）
- [ ] 安全基线核对：新配置写入是否原子 + 0600？新端点是否校验 Origin / workspace？
- [ ] 回归核对：既有行为未被误删误改（并发限制器、回退链、store 语义是重灾区）
- [ ] 跨层契约核对：新校验是否各入口同步？新字段是否三级回退？签名是否向后兼容？
- [ ] 决策提取：从本轮 spec 决策表摘跨功能有效的行 → 追加 docs/decisions.md

## 流程约定

- 分支 `feat/<topic>` → merge main；commit：conventional 前缀 + scope + 中文描述
- 功能流程：spec → plan → 实现 → 终审 → merge；spec/plan 本地留存 docs/superpowers/（gitignore）
- CHANGELOG 随功能更新；版本发布三处同步（契约 10）