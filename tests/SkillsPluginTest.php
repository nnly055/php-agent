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
        // AGENT_SKILLS_DIR= 显式中和 shell 里可能 export 的同名变量（镜像 McpPluginTest 的 AGENT_MCP 惯例）
        file_put_contents(
            $this->root . '/.env',
            "AGENT_WORKSPACE={$this->workspace}\nAGENT_SKILLS=on\nAGENT_SKILLS_DIR=\n" . $envExtra
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

    public function testShellExportedSkillsDirNeutralizedByEnvFile(): void
    {
        // 契约 11 精神：目标用户（shell 里 export 过 AGENT_SKILLS_DIR 的人）跑本文件
        // 不得让 shell 目录泄漏进装配——boot 的 .env 必须显式写 AGENT_SKILLS_DIR=（空值）中和
        putenv('AGENT_SKILLS_DIR=' . $this->workspace . '/team-skills');
        mkdir($this->workspace . '/team-skills/shell-skill', 0777, true);
        file_put_contents($this->workspace . '/team-skills/shell-skill/SKILL.md', "---\nname: shell-skill\ndescription: d\n---\nb");
        try {
            exec('rm -rf ' . escapeshellarg($this->workspace . '/.agent'));
            $kernel = $this->boot();
            self::assertNull($kernel->skillRegistry(), 'shell 导出的 AGENT_SKILLS_DIR 不应泄漏进测试装配');
        } finally {
            putenv('AGENT_SKILLS_DIR');
            exec('rm -rf ' . escapeshellarg($this->workspace . '/team-skills'));
        }
    }

    public function testSkippedSkillStillEmitsWarningToStderr(): void
    {
        // 静默丢失 bug 类：唯一技能因 name 为空被跳过时，诊断告警不得被 isEmpty() 提前返回吞掉。
        // STDERR 经子进程 runner 捕获（STDERR 常量进程内不可重定向）
        $workspace2 = sys_get_temp_dir() . '/php-agent-skills-warn-' . uniqid();
        $root2 = sys_get_temp_dir() . '/php-agent-skills-warn-root-' . uniqid();
        mkdir("{$workspace2}/.agent/skills/bad", 0777, true);
        file_put_contents("{$workspace2}/.agent/skills/bad/SKILL.md", "---\nname:   \ndescription: d\n---\nb");
        mkdir($root2, 0777, true);
        file_put_contents("{$root2}/.env", "AGENT_WORKSPACE={$workspace2}\nAGENT_SKILLS=on\n");
        try {
            $runner = dirname(__DIR__) . '/tests/fixtures/skills/skills-warn-runner.php';
            $cmd = sprintf(
                'PHP_AGENT_ROOT=%s PHP_AGENT_TEST_ROOT=%s %s %s 2>&1',
                escapeshellarg(dirname(__DIR__)),
                escapeshellarg($root2),
                escapeshellarg(PHP_BINARY),
                escapeshellarg($runner)
            );
            $out = (string) shell_exec($cmd);
            self::assertStringContainsString('name 为空', $out, '被跳过的技能必须留下 STDERR 诊断');
            self::assertStringContainsString('REGISTRY=null', $out, '零注册边界不变');
        } finally {
            exec('rm -rf ' . escapeshellarg($root2));
            exec('rm -rf ' . escapeshellarg($workspace2));
        }
    }
}
