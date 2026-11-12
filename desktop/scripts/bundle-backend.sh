#!/usr/bin/env bash
#
# 组装桌面 bundle 的 PHP 后端到 desktop/src-tauri/resources/php-agent/：
#   静态 php 二进制（static-php-cli 预编译产物，免系统 PHP 依赖）+ 内核代码目录
#   （src / bin / plugins / public / vendor），保持插件发现与 public 资源结构不变。
# 幂等：重复运行覆盖式刷新；php 二进制已存在则跳过下载。
#
# 用法: bash desktop/scripts/bundle-backend.sh
# 可选: PHP_VERSION=8.2.31 FORCE_PHP=1 bash desktop/scripts/bundle-backend.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)" # desktop/scripts → 项目根
DEST="$ROOT/desktop/src-tauri/resources/php-agent"

PHP_VERSION="${PHP_VERSION:-8.2.31}"
# 平台识别：GitHub Actions 的 windows runner（Git Bash）uname -s 形如 MINGW64_NT-*
case "$(uname -s)" in
  Darwin) PLATFORM=darwin ;;
  Linux)  PLATFORM=linux ;;
  MINGW*|MSYS*|CYGWIN*) PLATFORM=windows ;;
  *) PLATFORM="$(uname -s | tr '[:upper:]' '[:lower:]')" ;;
esac
ARCH="$(uname -m)"
[ "$ARCH" = "arm64" ] && ARCH=aarch64
IS_WINDOWS=0; [ "$PLATFORM" = "windows" ] && IS_WINDOWS=1
# windows 产物是 zip（内含 php.exe），其余平台 tar.gz（内含 php）
EXT=tar.gz; [ "$IS_WINDOWS" = "1" ] && EXT=zip
URL="https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-${PLATFORM}-${ARCH}.${EXT}"

mkdir -p "$DEST"

# ── 1) 静态 php 二进制（SKIP_STATIC_PHP=1 跳过，使用系统 php——Windows 矩阵用）──
IS_WINDOWS=0; [ "$PLATFORM" = "windows" ] && IS_WINDOWS=1
PHP_NAME=php; [ "$IS_WINDOWS" = "1" ] && PHP_NAME=php.exe
CACHE="$HOME/.cache/php-agent-desktop/php-${PHP_VERSION}-${PLATFORM}-${ARCH}"
PHP_BIN="$DEST/$PHP_NAME"

if [ "${SKIP_STATIC_PHP:-0}" = "1" ]; then
  PHP_BIN="$(command -v php)"
  echo "▸ 跳过静态 PHP，使用系统 PHP: $PHP_BIN"
elif [ ! -x "$PHP_BIN" ] || [ "${FORCE_PHP:-0}" = "1" ]; then
  mkdir -p "$(dirname "$CACHE")" /tmp/static-php-extract
  if [ -x "$CACHE" ] || [ -f "$CACHE" ]; then
    echo "▸ 使用缓存: $CACHE"
    cp "$CACHE" "$PHP_BIN"
  else
    echo "▸ 获取静态 PHP ${PHP_VERSION} (${PLATFORM}-${ARCH})..."
    ok=0
    for i in 1 2 3; do
      curl -sL --retry 3 -m 300 -o /tmp/static-php.$EXT "$URL" || true
      rm -rf /tmp/static-php-extract && mkdir -p /tmp/static-php-extract
      # 站点偶发返回 Cloudflare HTML，必须验证解包产物是可执行文件再采用
      if [ "$EXT" = "zip" ]; then
        unzip -o -q /tmp/static-php.$EXT -d /tmp/static-php-extract 2>/dev/null || { rm -f /tmp/static-php.$EXT; continue; }
        EXE=$(find /tmp/static-php-extract -name 'php.exe' | head -1)
        [ -n "$EXE" ] && cp "$EXE" /tmp/static-php-extract/php.exe && ok=1 && break
      else
        tar xzf /tmp/static-php.$EXT -C /tmp/static-php-extract 2>/dev/null || { rm -f /tmp/static-php.$EXT; continue; }
        [ -x /tmp/static-php-extract/php ] && ok=1 && break
      fi
      sleep 2
    done
    [ "$ok" = "1" ] || { echo "✗ 静态 PHP 下载失败（重试已耗尽）: $URL"; exit 1; }
    cp /tmp/static-php-extract/$PHP_NAME "$CACHE"
    cp "$CACHE" "$PHP_BIN"
  fi
  [ "$IS_WINDOWS" = "0" ] && chmod 755 "$PHP_BIN"
fi

# ── 2) 内核代码目录（保留 plugins/public/vendor 结构，插件发现零改动）──
# Windows（Git Bash）无 rsync，用 cp -R；rm -rf 先清实现覆盖语义
rm -rf "$DEST/src" "$DEST/bin" "$DEST/plugins" "$DEST/public" "$DEST/vendor" \
       "$DEST/composer.json" "$DEST/composer.lock"
for item in src bin plugins public vendor composer.json composer.lock; do
  cp -R "$ROOT/$item" "$DEST/$item"
done

# ── 3) 冒烟验证：二进制可执行 + autoload 可用 + 必需扩展 ──────
"$PHP_BIN" -v | head -1
# Git Bash 的 /d/... 路径原生 php.exe 不认识；cygpath -m 转正斜杠混合路径
# （Windows 下也可直接被 PHP 接受，且避免反斜杠在 PHP 字符串里的转义问题）
SMOKE_DEST="$DEST"
command -v cygpath >/dev/null 2>&1 && SMOKE_DEST="$(cygpath -m "$DEST")"
"$PHP_BIN" -r 'require "'"$SMOKE_DEST"'/vendor/autoload.php"; echo class_exists("PhpAgent\\Config") ? "autoload-ok" : exit(1), "\n";'
# 注意：不能用 `php -m | grep -q`——pipefail 下 grep 提前退出会吃掉 php 的 SIGPIPE 变成 race 失败，先落变量再匹配
MODULES="$("$PHP_BIN" -m 2>&1)"
for ext in curl mbstring sqlite3; do
  case "$MODULES" in
    *"$ext"*) ;;
    *) echo "✗ 静态 php 缺少必需扩展: $ext"; exit 1 ;;
  esac
done

SIZE="$(du -sh "$DEST" | cut -f1)"
echo "✓ PHP 后端已打包: $DEST ($SIZE)"