<?php
declare(strict_types=1);
namespace PhpAgent\Tests;

use PhpAgent\Skills\Skill;
use PhpAgent\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class SkillRegistryTest extends TestCase
{
    private function skill(string $name, string $desc = 'd', string $dir = '/tmp/x'): Skill
    {
        return new Skill($name, $desc, "body of {$name}", $dir);
    }

    public function testFindReturnsAddedSkill(): void
    {
        $r = new SkillRegistry();
        $r->add($this->skill('php-ext'));
        $s = $r->find('php-ext');
        self::assertNotNull($s);
        self::assertSame('php-ext', $s->name);
        self::assertSame('body of php-ext', $s->body);
        self::assertSame('/tmp/x', $s->dir);
    }

    public function testLaterAddOverridesSameName(): void
    {
        $r = new SkillRegistry();
        $r->add($this->skill('a', 'first'));
        $r->add($this->skill('a', 'second', '/other'));
        self::assertSame('second', $r->find('a')?->description);
        self::assertSame('/other', $r->find('a')?->dir);
    }

    public function testAllPreservesInsertionOrderAndIsEmpty(): void
    {
        $r = new SkillRegistry();
        self::assertTrue($r->isEmpty());
        $r->add($this->skill('b'));
        $r->add($this->skill('a'));
        self::assertSame(['b', 'a'], array_keys($r->all()));
        self::assertFalse($r->isEmpty());
    }

    public function testFindUnknownReturnsNull(): void
    {
        $r = new SkillRegistry();
        self::assertNull($r->find('nope'));
    }
}
