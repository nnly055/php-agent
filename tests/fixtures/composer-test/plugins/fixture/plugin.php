<?php

// 测试夹具：验证「composer 插件 → 项目插件」加载顺序（后者可覆盖前者）
require_once __DIR__ . '/src/OverridingPlugin.php';

return OverridingPlugin::class;
