<?php

declare(strict_types=1);

namespace PhpAgent\Ui;

use PhpAgent\Config;
use PhpAgent\LLM\StreamEvent;

/**
 * 终端 UI：ANSI 彩色流式渲染。
 */
final class ConsoleUi
{
    private const RESET = "\033[0m";
    private const DIM = "\033[2m";
    private const CYAN = "\033[36m";
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BOLD = "\033[1m";

    private bool $textStarted = false;
    private bool $inThinking = false;

    public function __construct(private readonly Config $config)
    {
    }

    public function banner(bool $fake = false): void
    {
        $provider = $fake ? 'fake(离线演示)' : $this->config->provider();
        $model = $fake ? 'demo-script' : ($this->config->model() ?: '(未配置 AGENT_MODEL)');
        $key = $fake ? '-' : ($this->config->apiKey() !== '' ? '已配置' : '⚠ 未设置');
        $workspace = $this->config->workspace();

        echo self::BOLD . "╭─────────────────────────────────────────────╮" . self::RESET . "\n";
        echo self::BOLD . "│  PHP Agent v0.1                             │" . self::RESET . "\n";
        echo "│  provider : {$provider}\n";
        echo "│  model    : {$model}\n";
        echo "│  api key  : {$key}\n";
        echo "│  workspace: {$workspace}\n";
        echo self::BOLD . "╰─────────────────────────────────────────────╯" . self::RESET . "\n";
        echo self::DIM . "  输入任务开始对话；/help 查看命令；/exit 退出" . self::RESET . "\n";
    }

    /** 读一行用户输入，EOF 返回 null。非 tty（管道）时退回 fgets，避免 readline 刷 termcap 警告 */
    public function readLine(): ?string
    {
        if (function_exists('readline') && @stream_isatty(STDIN)) {
            $line = readline(self::CYAN . '❯ ' . self::RESET);
            if ($line === false) {
                return null;
            }
            readline_add_history($line);
            return $line;
        }
        echo self::CYAN . '❯ ' . self::RESET;
        $line = fgets(STDIN);
        return $line === false ? null : rtrim($line, "\r\n");
    }

    public function handleEvent(StreamEvent $ev): void
    {
        switch ($ev->type) {
            case 'text_delta':
                if (!$this->textStarted) {
                    echo "\n";
                    $this->textStarted = true;
                }
                echo $ev->data['text'];
                fflush(STDOUT);
                break;

            case 'thinking_delta':
                if (!$this->textStarted) {
                    echo "\n" . self::DIM;
                    $this->textStarted = true;
                    $this->inThinking = true;
                } elseif (!$this->inThinking) {
                    echo self::DIM;
                    $this->inThinking = true;
                }
                echo $ev->data['text'];
                fflush(STDOUT);
                break;

            case 'tool_use_start':
                $this->resetTextState();
                echo "\n  " . self::YELLOW . '⚙ ' . $ev->data['toolName'] . self::RESET;
                fflush(STDOUT);
                break;

            case 'tool_input_delta':
                // 参数边流边攒，完整展示放到 tool_use_end
                break;

            case 'tool_use_end':
                $json = json_encode($ev->data['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                echo self::DIM . ' ' . $this->truncate((string) $json, 120) . self::RESET;
                fflush(STDOUT);
                break;

            case 'tool_result':
                $this->resetTextState();
                $result = (string) $ev->data['result'];
                $isError = str_starts_with($result, 'ERROR:');
                $color = $isError ? self::RED : self::GREEN;
                echo "\n  " . self::DIM . '↳ ' . $color . $this->truncate(str_replace("\n", ' ⏎ ', $result), 200) . self::RESET;
                fflush(STDOUT);
                break;

            case 'message_end':
                $this->resetTextState();
                echo "\n";
                fflush(STDOUT);
                break;

            case 'error':
                $this->resetTextState();
                echo "\n" . self::RED . '  ✗ ' . $ev->data['message'] . self::RESET . "\n";
                fflush(STDOUT);
                break;
        }
    }

    /**
     * 工具执行授权提示（ConfirmHook 的 CLI 交互面）。
     *
     * @return string 'y' | 'n' | 'a'（本次会话总是允许）
     */
    public function promptTool(string $tool, array $input): string
    {
        $this->resetTextState();
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo "\n  " . self::YELLOW . '⚙ 需要授权: ' . $tool . self::RESET
            . self::DIM . ' ' . $this->truncate((string) $json, 140) . self::RESET;
        fflush(STDOUT);

        if (!@stream_isatty(STDIN)) {
            echo self::DIM . "\n  （非交互终端，自动拒绝；AGENT_PERMISSIONS=off 可跳过）" . self::RESET . "\n";
            fflush(STDOUT);
            return 'n';
        }

        echo "\n  " . self::CYAN . '允许执行? [Y/n/a=本次会话总是允许] ' . self::RESET;
        fflush(STDOUT);
        $line = strtolower(trim((string) fgets(STDIN)));
        return match (true) {
            $line === '' || $line === 'y' || $line === 'yes' => 'y',
            $line === 'a' || $line === 'always' => 'a',
            default => 'n',
        };
    }

    public function error(string $message): void
    {
        $this->resetTextState();
        echo self::RED . "\n✗ " . $message . self::RESET . "\n";
    }

    public function info(string $message): void
    {
        echo self::DIM . $message . self::RESET . "\n";
    }

    public function goodbye(): void
    {
        echo self::DIM . "再见。\n" . self::RESET;
    }

    private function resetTextState(): void
    {
        if ($this->inThinking) {
            echo self::RESET;
            $this->inThinking = false;
        }
        $this->textStarted = false;
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
