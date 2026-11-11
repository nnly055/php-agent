<?php

declare(strict_types=1);

/**
 * 测试夹具：最小 MCP Streamable HTTP server。
 * initialize → 下发 Mcp-Session-Id；initialized 通知 → 202；
 * tools/list → application/json；tools/call → text/event-stream（SSE 单事件）；
 * method=boom → HTTP 500；无会话头 → 404。
 */
$body = file_get_contents('php://input');
$msg = json_decode((string) $body, true);
if (!is_array($msg) || !isset($msg['method'])) {
    http_response_code(400);
    exit;
}
$method = (string) $msg['method'];

if ($method === 'initialize') {
    header('Content-Type: application/json');
    header('Mcp-Session-Id: sess-test-123');
    echo json_encode([
        'jsonrpc' => '2.0', 'id' => $msg['id'],
        'result' => [
            'protocolVersion' => $msg['params']['protocolVersion'] ?? '2025-06-18',
            'capabilities' => ['tools' => []],
            'serverInfo' => ['name' => 'fake-http', 'version' => '0.0.1'],
        ],
    ]);
    exit;
}

if (($_SERVER['HTTP_MCP_SESSION_ID'] ?? '') !== 'sess-test-123') {
    http_response_code(404);
    exit;
}
if ($method === 'notifications/initialized') {
    http_response_code(202);
    exit;
}
if ($method === 'boom') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => '夹具故意失败']]);
    exit;
}
if ($method === 'tools/list') {
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0', 'id' => $msg['id'],
        'result' => ['tools' => [
            ['name' => 'weather', 'description' => '查天气',
             'inputSchema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]],
        ]],
    ]);
    exit;
}
if ($method === 'tools/call') {
    header('Content-Type: text/event-stream');
    $city = (string) ($msg['params']['arguments']['city'] ?? '?');
    $payload = json_encode([
        'jsonrpc' => '2.0', 'id' => $msg['id'],
        'result' => ['content' => [['type' => 'text', 'text' => "weather({$city}): 晴 25℃"]]],
    ], JSON_UNESCAPED_UNICODE);
    echo "event: message\ndata: {$payload}\n\n";
    exit;
}
http_response_code(404);
