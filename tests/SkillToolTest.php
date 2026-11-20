<?php
declare(strict_types=1);
namespace PhpAgent\Tests;

use PhpAgent\Agent\SkillTool;
use PhpAgent\Skills\Skill;
use PhpAgent\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillToolTest extends TestCase
{
    private function registry(array $specs): SkillRegistry
    {
        $r = new SkillRegistry();
        foreach ($specs as [$name, $desc, $body]) {
            $r->add(new Skill($name, $desc, $body, "/tmp/{$name}"));
        }
        return $r;
    }

    public function testDescriptionListsSkills(): void
    {
        $t = new SkillTool($this->registry([
            ['php-ext', '写 PHP 扩展的步骤', 'b1'],
            ['compact-guide', '会话压缩原理与调参', 'b2'],
        ]));
        self::assertSame('skill', $t->name());
        self::assertStringContainsString('可用技能:', $t->description());
        self::assertStringContainsString('- php-ext: 写 PHP 扩展的步骤', $t->description());
        self::assertStringContainsString('- compact-guide: 会话压缩原理与调参', $t->description());
    }

    public function testDescriptionTruncatesLongEntry(): void
    {
        $long = str_repeat('长', 300);
        $d = (new SkillTool($this->registry([['a', $long, 'b']])))->description();
        self::assertStringContainsString('…', $d);
        self::assertStringNotContainsString($long, $d);
    }

    public function testDescriptionListCap(): void
    {
        $specs = [];
        for ($i = 0; $i < 50; $i++) {
            $specs[] = ["s{$i}", str_repeat('描', 100), 'b'];
        }
        $d = (new SkillTool($this->registry($specs)))->description();
        self::assertStringContainsString('（技能过多，仅列出部分）', $d);
        self::assertStringNotContainsString('- s49:', $d);
    }

    public function testSchemaAndReadOnly(): void
    {
        $t = new SkillTool($this->registry([['a', 'd', 'b']]));
        self::assertTrue($t->readOnly());
        self::assertSame(['name'], $t->schema()['required']);
        self::assertSame('string', $t->schema()['properties']['name']['type']);
    }

    public function testExecuteReturnsBodyVerbatim(): void
    {
        $t = new SkillTool($this->registry([['php-ext', 'd', "# 步骤\n1. phpize"]]));
        self::assertSame("# 步骤\n1. phpize", $t->execute(['name' => 'php-ext']));
    }

    public function testExecuteUnknownListsAvailable(): void
    {
        $t = new SkillTool($this->registry([['a', 'd', 'b'], ['b-skill', 'd', 'b']]));
        $out = $t->execute(['name' => 'nope']);
        self::assertStringStartsWith("ERROR: 未知技能 'nope'", $out);
        self::assertStringContainsString('a, b-skill', $out);
    }

    public function testExecuteEmptyBodyReturnsEmptyString(): void
    {
        $t = new SkillTool($this->registry([['e', 'd', '']]));
        self::assertSame('', $t->execute(['name' => 'e']));
    }
}
