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
}
