<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Mcp\Channel;

use MagoAssistant\Mcp\Service\Mcp\Channel\ProcessChannel;
use MagoAssistant\Mcp\Service\Mcp\McpProcessException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessChannelTest extends TestCase
{
    private function fake(array $env = []): ServerConfig
    {
        return new ServerConfig('fake', [PHP_BINARY, dirname(__DIR__, 3) . '/Fakes/fake-mcp-server.php'], $env);
    }

    /**
     * @param resource $stream
     */
    private function readLine($stream, float $seconds = 5.0): string
    {
        $until = microtime(true) + $seconds;
        $line = '';
        while (!str_contains($line, "\n") && microtime(true) < $until) {
            $read = [$stream];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                $line .= (string)fread($stream, 8192);
            }
        }

        return $line;
    }

    #[Test]
    public function startsTheServerAndCarriesBytesBothWays(): void
    {
        $channel = new ProcessChannel();
        $channel->open($this->fake(), sys_get_temp_dir(), 10);
        try {
            self::assertTrue($channel->write('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' . "\n"));
            $answer = json_decode(trim($this->readLine($channel->output())), true);
            self::assertSame('fake-mcp', $answer['result']['serverInfo']['name']);
            self::assertNull($channel->exitCode(), 'still running');
        } finally {
            $channel->close(true);
        }
    }

    #[Test]
    public function reportsTheExitCodeAndStderrOfAServerThatQuits(): void
    {
        $channel = new ProcessChannel();
        $channel->open($this->fake(['FAKE_MCP_CRASH' => '1']), sys_get_temp_dir(), 10);
        self::assertStringContainsString('boom: fake crash', $this->readLine($channel->errors()));
        $until = microtime(true) + 5;
        while ($channel->exitCode() === null && microtime(true) < $until) {
            usleep(20000);
        }
        self::assertSame(3, $channel->exitCode());
        self::assertSame(3, $channel->exitCode(), 'asked twice, still the real code');
        $channel->close(false);
    }

    #[Test]
    public function aMissingBinaryIsAProcessError(): void
    {
        $this->expectException(McpProcessException::class);
        $this->expectExceptionMessage('Could not start MCP server "gone".');

        (new ProcessChannel())->open(new ServerConfig('gone', ['/nonexistent/mcp-binary']), sys_get_temp_dir(), 5);
    }

    #[Test]
    public function closeWithKillEndsTheProcessAndReleasesTheStreams(): void
    {
        $channel = new ProcessChannel();
        $channel->open($this->fake(), sys_get_temp_dir(), 10);
        $channel->close(true);

        self::assertNull($channel->output());
        self::assertNull($channel->errors());
        self::assertFalse($channel->write("x\n"));
    }
}
