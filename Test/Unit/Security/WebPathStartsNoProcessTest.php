<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hosts disable the process functions for web PHP; the code a web request runs must not call them. Only
 * ProcessChannel (command line), the worker's Relay and the Starter cron uses may.
 */
final class WebPathStartsNoProcessTest extends TestCase
{
    /** Every function that starts or forks a process; pcntl_signal and the like only handle signals. */
    private const FORBIDDEN = ['proc_open', 'popen', 'exec', 'shell_exec', 'system', 'passthru', 'pcntl_exec', 'pcntl_fork', 'pcntl_rfork', 'pcntl_unshare'];
    private const ALLOWED = ['Service/Mcp/Channel/ProcessChannel.php', 'Service/Worker/Relay.php', 'Service/Worker/Starter.php'];

    #[Test]
    public function noModuleFileOutsideTheTwoAllowedOnesCallsAProcessFunction(): void
    {
        $root = dirname(__DIR__, 3);
        $calls = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'Test/') || str_starts_with($relative, 'vendor/')
                || in_array($relative, self::ALLOWED, true)
            ) {
                continue;
            }
            foreach ($this->functionCalls((string)file_get_contents($file->getPathname())) as $name) {
                if (in_array(strtolower($name), self::FORBIDDEN, true)) {
                    $calls[] = $relative . ': ' . $name;
                }
            }
        }

        self::assertSame([], $calls);
    }

    #[Test]
    public function theCheckSeesACall(): void
    {
        self::assertSame(['proc_open'], $this->functionCalls('<?php $p = \\proc_open($c, [], $pipes); // exec(x)'));
    }

    /**
     * Names of global functions called in the code; comments, strings and methods do not count.
     *
     * @return string[]
     */
    private function functionCalls(string $code): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $names = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            $previous = $tokens[$i - 1] ?? null;
            $isMethodOrDeclaration = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
            if ($next === '(' && !$isMethodOrDeclaration) {
                $names[] = ltrim($token[1], '\\');
            }
        }

        return $names;
    }
}
