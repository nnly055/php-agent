<?php

declare(strict_types=1);

namespace PhpAgent\Server;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Config;
use PhpAgent\Hook\HookPipeline;
use PhpAgent\LLM\AnthropicClient;
use PhpAgent\LLM\OpenAICompatClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Session\SessionStore;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * 本地 SSE 服务：桌面应用前端的对接面。
 *
 *   GET  /status            → 运行状态（provider/model/workspace/permissions/…）
 *   GET  /sessions          → 会话列表
 *   GET  /sessions/{id}     → 会话历史
 *   DELETE /sessions/{id}   → 删除会话
 *   POST /confirm/{id}      → 工具授权决定（Task 5）
 *   POST /agent             → SSE 流式对话
 *   POST /config/test      → 测试连接（桌面设置对话框；不落审计/日志）
 *   GET  /config/profiles   → 当前 profiles 目录（含 apiKey，桌面本地回显用）
 *   POST /config/profiles   → 热更新 profiles 目录（校验失败 400）
 *   GET  /                  → 内置极简聊天页（Web 用，桌面不走这里）
 *
 * 仅绑 127.0.0.1；所有响应带 CORS 头（前端 origin 与 API 不同源）。
 */
final class AgentServer
{
    public function __construct(
        private readonly AgentLoop $loop,
        private readonly SessionStore $store,
        private readonly array $hooks,
        private readonly string $publicDir,
        private readonly Config $config,
        private readonly ProfileRegistry $profiles,
        private readonly ?ConfirmBroker $broker = null,
    ) {
    }

    public function handle(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        try {
            return $this->route($request);
        } catch (\Throwable $e) {
            // 任何异常（如 workspace 失效、存储后端抛错）都必须走 json()，保证错误响应也带 CORS 头
            return $this->json(500, ['error' => $e->getMessage()]);
        }
    }

    private function route(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        if ($method === 'OPTIONS') {
            return $this->cors(new Response(204));
        }

        if ($method === 'GET' && $path === '/status') {
            return $this->json(200, [
                'provider' => $this->config->provider(),
                'model' => $this->config->model(),
                'workspace' => $this->config->workspace(),
                'permissions' => $this->config->permissions(),
                'sessionStore' => $this->config->sessionStore(),
                'mcp' => $this->config->mcpEnabled(),
                'version' => Config::VERSION,
                'maxIterations' => $this->config->maxIterations(),
            ]);
        }

        if ($path === '/config/profiles') {
            // 凭据端点：CORS * 下简单 GET 无预检，浏览器跨域请求必带 Origin（可被任意网页发起；
            // 桌面 webview/本地工具/同源页面不带或同源），非白名单 Origin 一律拒绝，收敛 key 外泄面
            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '' && !$this->isAllowedOrigin($origin, $request->getHeaderLine('Host'))) {
                return $this->json(403, ['error' => 'Origin 不被允许']);
            }
            if ($method === 'GET') {
                // 原样返回原始列表（含 apiKey）：仅绑 127.0.0.1 的桌面本地接口，设置页需要回显
                return $this->json(200, $this->profiles->profiles());
            }
            if ($method === 'POST') {
                $body = json_decode((string) $request->getBody(), true);
                try {
                    $this->profiles->setProfiles(
                        is_array($body['profiles'] ?? null) ? $body['profiles'] : [],
                        (string) ($body['defaultProfile'] ?? ''),
                    );
                } catch (\InvalidArgumentException $e) {
                    return $this->json(400, ['error' => $e->getMessage()]);
                }
                return $this->json(200, ['ok' => true]);
            }
        }

        if ($method === 'GET' && $path === '/sessions') {
            $limit = (int) ($request->getQueryParams()['limit'] ?? 50);
            $metas = array_map(static fn ($m) => $m->toArray(), $this->store->list(max(1, $limit)));
            return $this->json(200, ['sessions' => $metas]);
        }

        if (preg_match('#^/sessions/([^/]+)$#', $path, $m)) {
            $id = urldecode($m[1]);
            if ($method === 'GET') {
                // meta（含本会话的 profileId/model 选择）：遍历列表找到对应会话；未知会话 → null
                $metas = $this->store->list(PHP_INT_MAX);
                $meta = null;
                foreach ($metas as $m) {
                    if ($m->id === $id) { $meta = $m->toArray(); break; }
                }
                return $this->json(200, ['id' => $id, 'messages' => $this->store->load($id), 'meta' => $meta]);
            }
            if ($method === 'DELETE') {
                $this->store->delete($id);
                return $this->json(200, ['ok' => true]);
            }
        }

        if ($method === 'POST' && preg_match('#^/confirm/([^/]+)$#', $path, $m)) {
            return $this->handleConfirm(urldecode($m[1]), $request);
        }

        if ($method === 'POST' && $path === '/agent') {
            return $this->handleAgent($request);
        }

        if ($method === 'POST' && $path === '/config/test') {
            return $this->handleConfigTest($request);
        }

        if ($method === 'GET' && ($path === '/' || $path === '/index.html')) {
            $html = @file_get_contents($this->publicDir . '/index.html');
            if ($html === false) {
                return $this->cors(new Response(500, ['Content-Type' => 'text/plain'], 'index.html 缺失'));
            }
            return $this->cors(new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html));
        }

        return $this->json(404, ['error' => 'Not Found']);
    }

    /**
     * /config/profiles 的 Origin 白名单：Tauri webview（生产 tauri://localhost 与
     * http(s)://tauri.localhost 两形态）+ vite dev server（http://localhost:1420），
     * 以及与请求 Host 头同源的 Origin（内置网页聊天页 http://127.0.0.1:<port> 的同源请求）。
     * authority 比较只看 host + port（缺省端口统一按 80），不比较 scheme。
     */
    private function isAllowedOrigin(string $origin, string $hostHeader = ''): bool
    {
        if (in_array($origin, ['tauri://localhost', 'http://tauri.localhost', 'http://localhost:1420'], true)) {
            return true;
        }
        if ($hostHeader === '') {
            return false;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        if (!is_string($originHost) || $originHost === '') {
            return false;
        }
        // Host 头是裸 authority（如 127.0.0.1:8091），补 scheme 再 parse
        $host = parse_url('http://' . $hostHeader, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $originPort = parse_url($origin, PHP_URL_PORT);
        $hostPort = parse_url('http://' . $hostHeader, PHP_URL_PORT);
        return $originHost === $host && ($originPort ?: 80) === ($hostPort ?: 80);
    }

    private function handleConfirm(string $id, \Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $decision = (string) ($body['decision'] ?? '');
        if (!in_array($decision, ['y', 'n', 'a'], true)) {
            return $this->json(400, ['error' => 'decision 必须是 y|n|a']);
        }
        if ($this->broker === null || !$this->broker->resolve($id, $decision)) {
            return $this->json(404, ['error' => '未知或已过期的确认 id']);
        }
        return $this->json(200, ['ok' => true]);
    }

    private function handleAgent(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            return $this->json(400, ['error' => 'message 不能为空']);
        }

        // per-turn 模型切换：在进 async 之前同步解析（校验失败立刻 400，SSE 已开流就无法改状态码）
        $profileId = isset($body['profile_id']) ? (string) $body['profile_id'] : null;
        $model = isset($body['model']) ? (string) $body['model'] : null;
        $explicit = $profileId !== null || $model !== null;
        $sid = (string) ($body['session_id'] ?? 'default');

        if (!$explicit) {
            // spec：未携带 → 该会话已存选择（meta）→ 默认档；save 仍传 null 保持 meta 不变
            foreach ($this->store->list(PHP_INT_MAX) as $m) {
                if ($m->id === $sid) {
                    $profileId = $m->profileId;
                    $model = $m->model;
                    break;
                }
            }
            try {
                $client = $this->profiles->resolve($profileId, $model);
            } catch (\InvalidArgumentException $e) {
                // 会话存的 profile 已被删除：回落默认档（保持旧会话可用，优先于严格报错）
                $client = $this->profiles->resolve(null, null);
            }
        } else {
            // 显式传入未知 profile/model：立刻 400
            try {
                $client = $this->profiles->resolve($profileId, $model);
            } catch (\InvalidArgumentException $e) {
                return $this->json(400, ['error' => $e->getMessage()]);
            }
        }
        $this->loop->setClient($client);
        $this->profiles->setActiveClient($client);
        // 会话选择落盘：显式指定 profile/model 才记录；都没传 → null（保留上次保存的选择，
        // 也避免回退出的 meta 值被当成新选择写回）
        $selection = $explicit ? ['profileId' => $profileId, 'model' => $model] : null;

        $messages = $this->store->load($sid);
        $stream = new ThroughStream();
        $broker = $this->broker;

        // SSE 客户端中途断开（react/http 在连接关闭时 close 响应体流）→ 立即按拒绝
        // 清理 pending 确认，不必等 agent 回合结束的 finally（discardActive 幂等）。
        $stream->on('close', function () use ($broker): void {
            $broker?->discardActive();
        });

        \React\Async\async(function () use ($stream, $sid, $message, $messages, $broker, $selection): void {
            try {
                $onEvent = function (StreamEvent $e) use ($stream): void {
                    $stream->write($this->sseFrame($e));
                    \React\Async\delay(0.001);
                };
                if ($broker !== null) {
                    $broker->setEmitter($onEvent);
                }
                $final = $this->loop->run($messages, $message, $onEvent);
                $this->store->save($sid, $messages, $selection);
                HookPipeline::fire($this->hooks, 'session_saved', ['sessionId' => $sid]);
                $stream->write($this->sseFrame(StreamEvent::finalAnswer($final)));
                $stream->end();
            } catch (\Throwable $e) {
                $stream->write($this->sseFrame(StreamEvent::error($e->getMessage())));
                $stream->end();
            } finally {
                $broker?->discardActive();
            }
        })();

        return $this->cors(new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], $stream));
    }

    /**
     * 测试连接：用表单当前值构造临时 Config，实例化对应 LLM client 发一次最小请求。
     * 消费到流自然结束（BaseSseClient 的消费契约；ping + AGENT_MAX_TOKENS=16 秒级完成）。
     * 响应不含 key，也不写任何日志/审计。
     */
    private function handleConfigTest(\Psr\Http\Message\ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $provider = strtolower(trim((string) ($body['provider'] ?? '')));
        if (!in_array($provider, ['openai', 'anthropic', 'fake'], true)) {
            return $this->json(400, ['error' => 'provider 必须是 openai|anthropic|fake']);
        }
        // base_url 仅允许 http(s)：桌面端任意网页可跨源探测本地随机端口的 HTTP 服务，
        // 若放行 file://、gopher:// 等任意 scheme，会把 /config/test 变成出站请求原语
        // （HTTP 错误信息还会把上游响应体片段回传给调用方）。空串保留 = provider 默认端点。
        $baseUrl = trim((string) ($body['base_url'] ?? ''));
        if ($baseUrl !== '' && !str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
            return $this->json(400, ['error' => 'base_url 必须以 http:// 或 https:// 开头']);
        }
        $tmp = Config::fromArray([
            'AGENT_PROVIDER' => $provider,
            'AGENT_API_KEY' => trim((string) ($body['api_key'] ?? '')),
            'AGENT_BASE_URL' => $baseUrl,
            'AGENT_MODEL' => trim((string) ($body['model'] ?? '')),
            // maxTokens 下限 1024：压低默认 8192，控制 ping 成本
            'AGENT_MAX_TOKENS' => '16',
        ]);
        if ($provider === 'fake') {
            return $this->json(200, ['ok' => true, 'message' => 'fake provider（离线）', 'latency_ms' => 0]);
        }
        if ($tmp->model() === '') {
            return $this->json(200, ['ok' => false, 'message' => '未配置模型', 'latency_ms' => 0]);
        }
        $client = $provider === 'anthropic' ? new AnthropicClient($tmp) : new OpenAICompatClient($tmp);
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'ping']]]];
        $start = (int) (microtime(true) * 1000);
        try {
            foreach ($client->stream($messages, [], '连接测试') as $_) {
                // 消费到自然结束；任一 HTTP/传输错误都会抛 LLMException
            }
        } catch (\Throwable $e) { // BaseSseClient 的 HTTP/传输错误统一抛 LLMException
            return $this->json(200, [
                'ok' => false,
                'message' => $e->getMessage(),
                'latency_ms' => (int) (microtime(true) * 1000) - $start,
            ]);
        }
        return $this->json(200, [
            'ok' => true,
            'message' => '连接成功',
            'latency_ms' => (int) (microtime(true) * 1000) - $start,
        ]);
    }

    private function sseFrame(StreamEvent $e): string
    {
        return 'data: ' . json_encode(['type' => $e->type, 'data' => $e->data], JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    private function json(int $status, array $data): Response
    {
        return $this->cors(new Response(
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($data, JSON_UNESCAPED_UNICODE)
        ));
    }

    private function cors(Response $resp): Response
    {
        return $resp
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type');
    }
}