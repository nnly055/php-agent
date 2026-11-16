# 决策日志

跨功能有效的决策留痕。追加规则：merge 前终审时，从本轮 spec 决策表摘出**下一个不相关功能
也会用到**的行追加于此，每条 ≤6 行；单功能实现细节不进来（那是本地工作稿的事）。
稳定契约的规则形态见 AGENTS.md，这里记"为什么"。

## 2026-11-12 | 凭据端点 Origin 白名单
决策：非白名单跨域请求凭据端点一律 403。
理由：收敛 API key 外泄面——本地服务虽绑 127.0.0.1，浏览器恶意页面仍可跨域打本地端口。
出处：commit cdd31ab

## 2026-11-13 | mcp.json 原子写 + 0600
决策：配置文件写入走临时文件 + 同目录 rename，权限 0600；读取区分缺失与不可读。
理由：半写状态会毁掉整份配置；不可读若当缺失处理，保存会静默覆盖原文件。
出处：commit b70e66a、c5cbf8a（Rust lib.rs 实现）

## 2026-11-13 | 图片传输 base64 内联 + body 上限 40MB
决策：/agent JSON body 内联 images，RequestBodyBufferMiddleware 显式提到 40MB，超限 413。
理由：react/http 默认 64KiB 超限**静默清空 body**，表现为误导性 400"message 不能为空"；
5 图 ×5MB 解码 ≈34MB base64，40MB 留富余。不引入 multipart（服务仅本地）。
出处：spec 2026-11-13-image-input §1.1、commit 1a29b27

## 2026-11-13 | compact 图片按固定 1600 token/图估算
决策：estimateTokens 剥离 image base64，按 IMAGE_TOKEN_COST=1600/图计。
理由：按 JSON 字符数估算会把 Retina 截图估成 25 万+ token，永久越过压缩阈值，
工具循环每轮触发一次多余的总结 LLM 调用。1600 ≈ 单图实际视觉 token 量级。
出处：spec 2026-11-13-image-input §1.2、commit b0d64fb

## 2026-11-13 | 客户端降采样优先于硬失败
决策：桌面发图前长边 >2000px 缩到 2000px，仍 >5MB 压 JPEG 质量，仍失败才报错。
理由：macOS Retina 全屏截图普遍 >5MB，硬失败会让主路径不可用；Anthropic 服务端本就
缩到 ~1568px，客户端预处理不损效果、省 token。
出处：spec 2026-11-13-image-input §2、commit 4da8cb8

## 2026-11-15 | thinking 块方案 A：历史存 thinking + signature
决策：归一化历史新增 thinking（含 signature）/ redacted_thinking 块，Anthropic 回放、openai 端剥离。
理由：协议硬约束——extended thinking + 工具循环不回传 thinking 块，第二轮必 400。
一次性按协议做对，为后续思维链特性打地基。
出处：spec 2026-11-15-model-picker-and-effort 决策表、commit 7363cf8

## 2026-11-15 | effort 五档词表，off = 不发字段
决策：off/low/medium/high/max 离散五档，默认 off；off 不发任何思考字段。
理由：off = 现行为零变化，旧客户端/不兼容端点无行为突变；不兼容端点显式选强度时透传上游报错。
出处：spec 2026-11-15-model-picker-and-effort 决策表

## 2026-11-15 | store 覆盖用 array_key_exists，禁用 ??
决策：save 组装改 array_key_exists 判定——键存在即权威，含显式 null。
理由：?? 把"显式 null"当缺失，/effort-only 会话会把已存 profile 抹掉（静默丢失 bug 类）。
出处：spec 2026-11-15-model-picker-and-effort 决策表、commit 8dbca24

## 2026-11-15 | 无 signature 的 thinking 块不入历史
决策：signature 非空才入历史，无签名块仅 UI 透传。
理由：Anthropic 回放无签名块必 400；openai 端 reasoning_content 本就无签名，入了也是脏数据。
出处：spec 2026-11-15-model-picker-and-effort 决策表