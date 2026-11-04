<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Transport;

use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransportResolverTest extends TestCase
{
    #[Test]
    public function resolvesByTheServersTransportValue(): void
    {
        $stdio = new FakeTransport();
        $resolver = new TransportResolver(['stdio' => $stdio]);

        self::assertSame($stdio, $resolver->for(new ServerConfig('a', ['php'])));
    }

    #[Test]
    public function anUnknownTransportIsAnMcpExceptionNamingTheServer(): void
    {
        $resolver = new TransportResolver(['stdio' => new FakeTransport()]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('MCP server "remote" uses transport "http", which is not available.');
        $resolver->for(new ServerConfig('remote', [], transport: ServerConfig::TRANSPORT_HTTP));
    }

    #[Test]
    public function aNonTransportEntryIsRejectedLikeAMissingOne(): void
    {
        $resolver = new TransportResolver(['stdio' => new \stdClass()]);

        $this->expectException(McpException::class);
        $resolver->for(new ServerConfig('a', ['php']));
    }
}
