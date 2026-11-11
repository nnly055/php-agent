<?php

// compact 插件：上下文压缩（before_llm_request 自动阈值 + /compact 手动命令）
// 禁用方式：重命名本文件为 plugin.php.disabled
require_once __DIR__ . '/src/CompactionResult.php';
require_once __DIR__ . '/src/Compactor.php';
require_once __DIR__ . '/src/CompactCommand.php';
require_once __DIR__ . '/src/CompactHook.php';
require_once __DIR__ . '/src/CompactPlugin.php';

return PhpAgent\Plugin\Compact\CompactPlugin::class;
