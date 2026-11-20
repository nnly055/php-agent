<?php

declare(strict_types=1);

namespace PhpAgent\Skills;

/**
 * 技能加载器：扫描目录、解析 SKILL.md frontmatter、填充 SkillRegistry。
 *
 * 扫描根两用：根目录自带 SKILL.md → 根即单个技能；否则每个含 SKILL.md 的子目录是一个技能。
 * 依序扫描多个目录，后者同名覆盖前者（与插件覆盖语义一致），覆盖时记 warning。
 * 所有 warning 累积在本实例上，由调用方决定打印；scan 之间不重置（测试以新实例隔离）。
 */
final class SkillLoader
{
    /** @var string[] */
    private array $warnings = [];

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @param string[] $dirs 依序扫描；workspace 用于附件可读性告警（路径须在 workspace 内） */
    public function scan(array $dirs, string $workspace): SkillRegistry
    {
        $registry = new SkillRegistry();
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue; // 目录不存在：静默跳过（AGENT_SKILLS_DIR 允许指向尚未创建的目录）
            }
            $this->warnIfOutsideWorkspace($dir, $workspace);
            if (is_file($dir . '/SKILL.md')) {
                $this->addSkill($registry, $dir);
                continue;
            }
            $entries = scandir($dir) ?: []; // 默认字母序，保证确定性
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $sub = $dir . '/' . $entry;
                if (is_dir($sub)) {
                    $this->addSkill($registry, $sub);
                }
            }
        }
        return $registry;
    }

    private function addSkill(SkillRegistry $registry, string $dir): void
    {
        $file = $dir . '/SKILL.md';
        if (!is_file($file) || !is_readable($file)) {
            return; // 缺 SKILL.md / 不可读：静默跳过
        }
        [$rawName, $description, $body] = $this->parseFile($file);

        // frontmatter 显式给出空 name → 跳过 + 告警（不回退目录名：显式空值是作者笔误信号）
        if ($rawName !== null && trim($rawName) === '') {
            $this->warnings[] = "技能目录 '{$dir}' 的 name 为空，已跳过";
            return;
        }
        $name = $rawName !== null ? trim($rawName) : basename($dir);
        if (trim($name) === '') {
            $this->warnings[] = "技能目录 '{$dir}' 的 name 为空，已跳过";
            return;
        }

        $prev = $registry->find($name);
        if ($prev !== null) {
            $this->warnings[] = "技能 '{$name}' 被 {$dir} 覆盖（原 {$prev->dir}）";
        }
        $registry->add(new Skill($name, $description, $body, $dir));
    }

    /**
     * 解析 SKILL.md → [?string name, string description, string body]。
     * name 为 null = frontmatter 未给出（调用方回退目录名）。
     * 兼容 BOM 与 CRLF；未闭合的 frontmatter 按无 frontmatter 处理（全文为正文，宽容）。
     *
     * @return array{0: ?string, 1: string, 2: string}
     */
    private function parseFile(string $file): array
    {
        $content = (string) file_get_contents($file);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3); // 去 BOM
        }
        $lines = array_map(static fn (string $l) => rtrim($l, "\r"), explode("\n", $content));

        // 首个非空行不是 '---' → 无 frontmatter，全文为正文
        $first = null;
        foreach ($lines as $i => $line) {
            if (trim($line) !== '') {
                $first = $i;
                break;
            }
        }
        if ($first === null || trim($lines[$first]) !== '---') {
            return [null, '', implode("\n", $lines)];
        }

        $name = null;
        $description = '';
        $end = null;
        $count = count($lines);
        for ($i = $first + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) === '---') {
                $end = $i;
                break;
            }
            if (preg_match('/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/', $line, $m) === 1) {
                if ($m[1] === 'name' && $name === null) {
                    $name = trim($m[2]);
                } elseif ($m[1] === 'description' && $description === '') {
                    $description = trim($m[2]); // 值里的冒号原样保留（.* 贪婪吃到行尾）
                }
                // 其余键忽略（前向兼容）
            }
        }
        if ($end === null) {
            return [null, '', implode("\n", $lines)]; // 未闭合
        }

        return [$name, $description, implode("\n", array_slice($lines, $end + 1))];
    }

    private function warnIfOutsideWorkspace(string $dir, string $workspace): void
    {
        $real = realpath($dir);
        if ($real === false) {
            return; // 无法解析的路径不告警（后续 is_file 自然跳过）
        }
        // 双方 realpath 规范化：macOS /var → /private/var 等符号链接漂移会导致误报
        $wsReal = realpath($workspace);
        $wsReal = $wsReal !== false ? rtrim($wsReal, '/') : rtrim($workspace, '/');
        if ($real !== $wsReal && !str_starts_with($real, $wsReal . '/')) {
            $this->warnings[] = "技能目录在 workspace 外，附件对模型不可读: {$dir}";
        }
    }
}
