#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 测试夹具：最小 MCP stdio server。行式 JSON-RPC。
 * 提供 echo / envdump / fail / ping(只读注解) / touch(写注解) 工具；
 * initialize 应答后主动发一条 notification，用于测试客户端的通知容忍。
 */

fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']) . "\n");
fflush(STDOUT);

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        continue;
    }
    $resp = ['jsonrpc' => '2.0', 'id' => $msg['id'] ?? null];
    switch ($msg['method']) {
        case 'initialize':
            $resp['result'] = [
                'protocolVersion' => $msg['params']['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'fake-stdio', 'version' => '0.0.1'],
            ];
            break;
        case 'tools/list':
            $resp['result'] = ['tools' => [
                ['name' => 'echo', 'description' => '回显输入文本',
                 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
                ['name' => 'envdump', 'description' => '返回环境变量 MCP_TEST_VAR',
                 'inputSchema' => ['type' => 'object', 'properties' => []]],
                ['name' => 'fail', 'description' => '总是返回 isError',
                 'inputSchema' => ['type' => 'object', 'properties' => []]],
                ['name' => 'ping', 'description' => '只读探测',
                 'inputSchema' => ['type' => 'object', 'properties' => []],
                 'annotations' => ['readOnlyHint' => true]],
                ['name' => 'touch', 'description' => '模拟写操作',
                 'inputSchema' => ['type' => 'object', 'properties' => []],
                 'annotations' => ['readOnlyHint' => false]],
            ]];
            break;
        case 'tools/call':
            $name = (string) ($msg['params']['name'] ?? '');
            $args = is_array($msg['params']['arguments'] ?? null) ? $msg['params']['arguments'] : [];
            if ($name === 'echo') {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => 'echo: ' . (string) ($args['text'] ?? '')]]];
            } elseif ($name === 'envdump') {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => 'env:' . (string) getenv('MCP_TEST_VAR')]]];
            } elseif ($name === 'fail') {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => '夹具故意失败']], 'isError' => true];
            } else {
                $resp['result'] = ['content' => [['type' => 'text', 'text' => 'ok ' . $name]]];
            }
            break;
        default:
            $resp['error'] = ['code' => -32601, 'message' => "method not found: {$msg['method']}"];
    }
    fwrite(STDOUT, json_encode($resp, JSON_UNESCAPED_UNICODE) . "\n");
    fflush(STDOUT);
}
