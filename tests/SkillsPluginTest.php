<?php
declare(strict_types=1);
namespace PhpAgent\Tests;

use PhpAgent\Command\CommandContext;
use PhpAgent\Config;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\Kernel\PluginManager;
use PHPUnit\Framework\TestCase;

final class SkillsPluginTest extends TestCase
{
    private string $root;
    private string $workspace;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/php-agent-skills-root-' . uniqid();
        $this->workspace = sys_get_temp_dir() . '/php-agent-skills-ws-' . uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->workspace . '/.agent/skills/php-ext', 0777, true);
        file_put_contents(
            $this->workspace . '/.agent/skills/php-ext/SKILL.md',
            "---\nname: php-ext\ndescription: 写 PHP 扩展的步骤\n---\n先跑 phpize。"
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        exec('rm -rf ' . escapeshellarg($this->workspace));
    }

    private function boot(string $envExtra = ''): AgentKernel
    {
        file_put_contents(
            $this->root . '/.env',
            "AGENT_WORKSPACE={$this->workspace}\nAGENT_SKILLS=on\n" . $envExtra
        );
        $kernel = new AgentKernel(Config::load($this->root));
        PluginManager::load($kernel, $this->root);
        return $kernel;
    }

    public function testToolAndRegistryRegisteredWhenSkillsExist(): void
    {
        $kernel = $this->boot();
        self::assertContains('skill', $kernel->toolRegistry()->names());
        self::assertNotNull($kernel->skillRegistry());
        self::assertNotNull($kernel->skillRegistry()->find('php-ext'));
    }

    public function testNoSkillsRegistersNothing(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workspace . '/.agent'));
        $kernel = $this->boot();
        self::assertNotContains('skill', $kernel->toolRegistry()->names());
        self::assertNull($kernel->skillRegistry());
        self::assertNull($kernel->command('skills'));
        self::assertNull($kernel->command('skill'));
    }

    public function testAgentSkillsOffDisablesAll(): void
    {
        $kernel = $this->boot('AGENT_SKILLS=off');
        self::assertNotContains('skill', $kernel->toolRegistry()->names());
        self::assertNull($kernel->skillRegistry());
        self::assertNull($kernel->command('skills'));
        self::assertNull($kernel->command('skill'));
    }

    public function testExtraDirFromEnvLoadsAndOverrides(): void
    {
        $extra = sys_get_temp_dir() . '/php-agent-skills-extra-' . uniqid();
        mkdir("{$extra}/php-ext", 0777, true);
        file_put_contents("{$extra}/php-ext/SKILL.md", "---\nname: php-ext\ndescription: 覆盖版描述\n---\n覆盖正文");
        try {
            $kernel = $this->boot("AGENT_SKILLS_DIR={$extra}\n");
            $s = $kernel->skillRegistry()->find('php-ext');
            self::assertNotNull($s);
            self::assertSame('覆盖版描述', $s->description, 'AGENT_SKILLS_DIR 在默认目录之后，同名覆盖');
        } finally {
            exec('rm -rf ' . escapeshellarg($extra));
        }
    }

    public function testRelativeExtraDirResolvedAgainstWorkspace(): void
    {
        mkdir($this->workspace . '/team-skills/guide', 0777, true);
        file_put_contents($this->workspace . '/team-skills/guide/SKILL.md', "---\nname: guide\ndescription: d\n---\nb");
        $kernel = $this->boot("AGENT_SKILLS_DIR=team-skills\n");
        self::assertNotNull($kernel->skillRegistry()->find('guide'));
    }

    public function testSkillsCommandListsEntries(): void
    {
        $kernel = $this->boot();
        $messages = [];
        $sessionId = 't';
        $selection = null;
        $out = $kernel->command('skills')->execute([], new CommandContext($kernel, $messages, $sessionId, $selection));
        self::assertStringContainsString('php-ext', $out);
        self::assertStringContainsString('写 PHP 扩展的步骤', $out);
        self::assertStringContainsString('/.agent/skills', $out);
        self::assertStringContainsString('/skill <name>', $out);
    }

    public function testSkillCommandInjectsUserMessage(): void
    {
        $kernel = $this->boot();
        $messages = [];
        $sessionId = 't';
        $selection = null;
        $out = $kernel->command('skill')->execute(['php-ext'], new CommandContext($kernel, $messages, $sessionId, $selection));
        self::assertStringStartsWith('✓ 已加载技能 php-ext', $out);
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]['role']);
        $text = $messages[0]['content'][0]['text'];
        self::assertStringContainsString('<skill name="php-ext">', $text);
        self::assertStringContainsString('先跑 phpize。', $text);
    }

    public function testSkillCommandUnknownName(): void
    {
        $kernel = $this->boot();
        $messages = [];
        $sessionId = 't';
        $selection = null;
        $out = $kernel->command('skill')->execute(['nope'], new CommandContext($kernel, $messages, $sessionId, $selection));
        self::assertStringStartsWith("✗ 未知技能 'nope'", $out);
        self::assertStringContainsString('php-ext', $out);
        self::assertSame([], $messages, '失败路径不得污染历史');
    }
}
