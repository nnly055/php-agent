<?php
declare(strict_types=1);
namespace PhpAgent\Tests;

use PhpAgent\Skills\SkillLoader;
use PHPUnit\Framework\TestCase;

final class SkillLoaderTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/php-agent-skills-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp)); // 递归清理技能夹具树
    }

    /** 建一个技能目录：<root>/<name>/SKILL.md，返回目录绝对路径 */
    private function makeSkill(string $root, string $name, string $fileContent): string
    {
        $dir = "{$root}/{$name}";
        mkdir($dir, 0777, true);
        file_put_contents("{$dir}/SKILL.md", $fileContent);
        return $dir;
    }

    public function testFullFrontmatter(): void
    {
        $dir = $this->makeSkill($this->tmp, 'php-ext', "---\nname: php-ext\ndescription: 写 PHP 扩展的步骤\nlicense: MIT\nignored-key: x\n---\n\n# 步骤\n先读 phpize 文档。\n");
        $r = (new SkillLoader())->scan([$dir], $this->tmp);
        $s = $r->find('php-ext');
        self::assertNotNull($s);
        self::assertSame('写 PHP 扩展的步骤', $s->description);
        self::assertSame("\n# 步骤\n先读 phpize 文档。\n", $s->body, '正文 = 第二个 --- 之后原文原样');
        self::assertSame($dir, $s->dir);
    }

    public function testMissingNameUsesDirName(): void
    {
        $dir = $this->makeSkill($this->tmp, 'guide', "---\ndescription: d\n---\n正文");
        $r = (new SkillLoader())->scan([$dir], $this->tmp);
        self::assertSame('guide', $r->find('guide')?->name);
        self::assertSame('正文', $r->find('guide')?->body);
    }

    public function testNoFrontmatterWholeFileIsBody(): void
    {
        $this->makeSkill($this->tmp, 'plain', "# 纯正文\n没有 frontmatter");
        $r = (new SkillLoader())->scan([$this->tmp], $this->tmp);
        $s = $r->find('plain');
        self::assertSame('', $s->description);
        self::assertStringContainsString('没有 frontmatter', $s->body);
    }

    public function testCrlfFrontmatterParses(): void
    {
        $this->makeSkill($this->tmp, 'win', "---\r\nname: win\r\ndescription: d\r\n---\r\nbody");
        $r = (new SkillLoader())->scan([$this->tmp], $this->tmp);
        self::assertNotNull($r->find('win'));
        self::assertSame('d', $r->find('win')?->description);
    }

    public function testDescriptionValueWithColons(): void
    {
        $this->makeSkill($this->tmp, 'c', "---\nname: c\ndescription: 用法: 先读后写: 再验证\n---\nb");
        $r = (new SkillLoader())->scan([$this->tmp], $this->tmp);
        self::assertSame('用法: 先读后写: 再验证', $r->find('c')?->description);
    }

    public function testEmptyBodyLoads(): void
    {
        $this->makeSkill($this->tmp, 'empty', "---\nname: empty\ndescription: d\n---\n");
        $r = (new SkillLoader())->scan([$this->tmp], $this->tmp);
        self::assertSame('', $r->find('empty')?->body);
    }

    public function testSkipsMissingSkillMdAndNonDirectories(): void
    {
        mkdir("{$this->tmp}/no-skill-md", 0777, true);          // 缺 SKILL.md
        file_put_contents("{$this->tmp}/a-plain-file", 'x');    // 非目录条目
        $r = (new SkillLoader())->scan([$this->tmp], $this->tmp);
        self::assertTrue($r->isEmpty());
    }

    public function testMissingScanDirSkippedSilently(): void
    {
        $loader = new SkillLoader();
        $r = $loader->scan(["{$this->tmp}/does-not-exist"], $this->tmp);
        self::assertTrue($r->isEmpty());
        self::assertSame([], $loader->warnings());
    }

    public function testEmptyNameSkippedWithWarning(): void
    {
        // 目录名 '  ' 不可行，改用 frontmatter 显式空 name
        $this->makeSkill($this->tmp, 'bad', "---\nname:   \ndescription: d\n---\nb");
        $loader = new SkillLoader();
        $r = $loader->scan([$this->tmp], $this->tmp);
        self::assertTrue($r->isEmpty());
        self::assertCount(1, $loader->warnings());
        self::assertStringContainsString('name 为空', $loader->warnings()[0]);
    }

    public function testLaterDirOverridesSameNameWithWarning(): void
    {
        $d1 = $this->makeSkill("{$this->tmp}/one", 'x', "---\nname: x\ndescription: first\n---\nb1");
        @mkdir("{$this->tmp}/two", 0777, true);
        $d2 = $this->makeSkill("{$this->tmp}/two", 'x', "---\nname: x\ndescription: second\n---\nb2");
        $loader = new SkillLoader();
        $r = $loader->scan([$d1, $d2], $this->tmp);
        self::assertSame('second', $r->find('x')?->description);
        self::assertCount(1, $loader->warnings());
        self::assertStringContainsString("技能 'x' 被 {$d2} 覆盖（原 {$d1}）", $loader->warnings()[0]);
    }

    public function testOutsideWorkspaceAddsWarningButStillLoads(): void
    {
        $outside = sys_get_temp_dir() . '/php-agent-skills-out-' . uniqid();
        mkdir($outside, 0777, true);
        $dir = $this->makeSkill($outside, 'ext', "---\nname: ext\ndescription: d\n---\nb");
        $loader = new SkillLoader();
        $r = $loader->scan([$dir], $this->tmp); // workspace = $this->tmp，技能在 $outside
        self::assertNotNull($r->find('ext'));
        $hit = array_values(array_filter($loader->warnings(), fn (string $w) => str_contains($w, 'workspace 外')));
        self::assertCount(1, $hit);
        exec('rm -rf ' . escapeshellarg($outside));
    }

    public function testAlphabeticalScanOrderIsDeterministic(): void
    {
        $this->makeSkill($this->tmp, 'b-skill', "---\nname: b-skill\ndescription: B\n---\nb");
        $this->makeSkill($this->tmp, 'a-skill', "---\nname: a-skill\ndescription: A\n---\nb");
        $keys = array_keys((new SkillLoader())->scan([$this->tmp], $this->tmp)->all());
        self::assertSame(['a-skill', 'b-skill'], $keys); // scandir 字母序
    }
}
