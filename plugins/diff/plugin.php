<?php

// diff 插件：write_file 变更可视化（覆盖工具 + 变更预览钩子）
// 禁用方式：重命名本文件为 plugin.php.disabled
require_once __DIR__ . '/src/DiffRenderer.php';
require_once __DIR__ . '/src/DiffWriteFileTool.php';
require_once __DIR__ . '/src/DiffPreviewHook.php';
require_once __DIR__ . '/src/DiffPlugin.php';

return PhpAgent\Plugin\Diff\DiffPlugin::class;
