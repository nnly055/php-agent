<?php

declare(strict_types=1);

namespace PhpAgent\Tests;

use PhpAgent\Agent\AgentLoop;
use PhpAgent\Kernel\AgentKernel;
use PhpAgent\LLM\FakeLLMClient;
use PhpAgent\LLM\StreamEvent;
use PhpAgent\Plugin\Diff\DiffPlugin;
use PhpAgent\Plugin\Diff\DiffRenderer;
use PhpAgent\Tools\WriteFileTool;
use PHPUnit\Framework\TestCase;

// 插件类不经 composer autoload（项目插件由 plugin.php require），测试中显式引入
require_once dirname(__DIR__) . '/plugins/diff/src/DiffRenderer.php';
require_once dirname(__DIR__) . '/plugins/diff/src/DiffWriteFileTool.php';
require_once dirname(__DIR__) . '/plugins/diff/src/DiffPreviewHook.php';
require_once dirname(__DIR__) . '/plugins/diff/src/DiffPlugin.php';

/**
 * diff 插件：工具覆盖、新文件/覆盖 diff、预览钩子、循环集成。
 */
final class DiffPluginTest extends TestCase
{
    private string $workspace;
    private AgentKernel $kernel;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/php-agent-diff-' . uniqid();
        mkdir($this->workspace, 0777, true);
        putenv('AGENT_WORKSPACE=' . $this->workspace);
        $this->kernel = new AgentKernel(\PhpAgent\Config::load($this->workspace));
        // 先注册内置 write_file，再由 diff 插件覆盖（与真实装配顺序一致）
        $this->kernel->registerTool(new WriteFileTool($this->workspace));
        (new DiffPlugin())->register($this->kernel);
    }

    protected function tearDown(): void
    {
        putenv('AGENT_WORKSPACE');
        foreach (glob($this->workspace . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->workspace);
    }

    public function testOverridesBuiltinWriteFile(): void
    {
        self::assertSame(['write_file'], $this->kernel->toolRegistry()->names(), '同名覆盖，不新增工具名');
        self::assertStringContainsString(
            'diff',
            $this->kernel->toolRegistry()->specs()[0]['description'],
            '覆盖后的描述含 diff 说明'
        );
    }

    public function testNewFileReturnsDiffWithAdditions(): void
    {
        $out = $this->kernel->toolRegistry()->execute('write_file', [
            'path' => 'new.php',
            'content' => "<?php\n\necho 'a';\n",
        ]);
        self::assertStringContainsString('OK: 已写入', $out);
        self::assertStringContainsString('+++ 新内容', $out);
        self::assertStringContainsString("+echo 'a';", $out);
        self::assertFileExists($this->workspace . '/new.php');
    }

    public function testOverwriteReturnsRedGreenDiff(): void
    {
        file_put_contents($this->workspace . '/app.php', "<?php\n\n\$a = 1;\n\$b = 2;\n");

        $out = $this->kernel->toolRegistry()->execute('write_file', [
            'path' => 'app.php',
            'content' => "<?php\n\n\$a = 1;\n\$b = 3;\n",
        ]);

        self::assertStringContainsString('OK: 已写入', $out);
        self::assertStringContainsString('$b = 2;', $out, '旧行以 - 展示');
        self::assertStringContainsString('$b = 3;', $out, '新行以 + 展示');
        self::assertSame("<?php\n\n\$a = 1;\n\$b = 3;\n", file_get_contents($this->workspace . '/app.php'), '实际写入为新内容');
    }

    public function testIdenticalContentSkipsDiff(): void
    {
        file_put_contents($this->workspace . '/same.txt', 'hello');
        $out = $this->kernel->toolRegistry()->execute('write_file', [
            'path' => 'same.txt',
            'content' => 'hello',
        ]);
        self::assertStringNotContainsString('+++', $out);
    }

    public function testPreviewHookPrintsDiffBeforeConfirm(): void
    {
        file_put_contents($this->workspace . '/x.txt', "old1\nold2\n");
        $hook = new \PhpAgent\Plugin\Diff\DiffPreviewHook($this->workspace);

        $this->expectOutputRegex('/变更预览.*[\x1b\[0-9;m]*-old1.*\+new1/s');
        $hook->handle('before_tool_call', [
            'tool' => 'write_file',
            'input' => ['path' => 'x.txt', 'content' => "new1\nnew2\n"],
        ]);
    }

    public function testPreviewHookNewFileSummary(): void
    {
        $hook = new \PhpAgent\Plugin\Diff\DiffPreviewHook($this->workspace);
        $this->expectOutputRegex('/新文件/');
        $hook->handle('before_tool_call', [
            'tool' => 'write_file',
            'input' => ['path' => 'brand-new.txt', 'content' => "a\nb\n"],
        ]);
        $this->expectOutputString('');
    }

    public function testHookIgnoresOtherTools(): void
    {
        $hook = new \PhpAgent\Plugin\Diff\DiffPreviewHook($this->workspace);
        $this->expectOutputString('');
        $hook->handle('before_tool_call', ['tool' => 'run_command', 'input' => ['command' => 'ls']]);
    }

    public function testDiffRendererTruncatesLongDiff(): void
    {
        $old = implode("\n", array_fill(0, 200, 'line'));
        $new = implode("\n", array_fill(0, 200, 'changed'));
        $out = DiffRenderer::render($old, $new, 20);
        self::assertStringContainsString('已截断显示前 20 行', $out);
        self::assertLessThan(60, count(preg_split('/\n/', $out)), '截断后行数受限');
    }

    public function testAgentLoopCarriesDiffInToolResult(): void
    {
        file_put_contents($this->workspace . '/loop.txt', "before\n");
        $script = [
            [
                StreamEvent::toolStart('t1', 'write_file'),
                StreamEvent::toolEnd('t1', 'write_file', ['path' => 'loop.txt', 'content' => "after\n"]),
                StreamEvent::end('tool_use'),
            ],
            [StreamEvent::text('done'), StreamEvent::end('end_turn')],
        ];
        $loop = new AgentLoop(new FakeLLMClient($script), $this->kernel->toolRegistry(), 'sys', 10);
        $messages = [];
        $loop->run($messages, '覆盖 loop.txt');

        $toolResult = $messages[2]['content'][0]['content'];
        self::assertStringContainsString('OK: 已写入', $toolResult);
        self::assertStringContainsString('-before', $toolResult);
        self::assertStringContainsString('+after', $toolResult);
    }
}
