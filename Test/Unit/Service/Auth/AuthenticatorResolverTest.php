<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Auth;

use MagoAssistant\Mcp\Service\Auth\AuthenticatorResolver;
use MagoAssistant\Mcp\Service\Auth\BearerTokenAuthenticator;
use MagoAssistant\Mcp\Service\Auth\NoneAuthenticator;
use MagoAssistant\Mcp\Service\Mcp\McpAuthenticationException;
use MagoAssistant\Mcp\Service\Mcp\McpException;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticatorResolverTest extends TestCase
{
    #[Test]
    public function noneSendsNoHeadersAndAlwaysHasCredentials(): void
    {
        $auth = (new AuthenticatorResolver())->for(new ServerConfig('a', [], transport: 'http'));

        self::assertInstanceOf(NoneAuthenticator::class, $auth);
        self::assertSame([], $auth->getHeaders(7));
        self::assertTrue($auth->hasCredentials(null));
        self::assertFalse($auth->onUnauthorized(7));
    }

    #[Test]
    public function bearerSendsTheToken(): void
    {
        $auth = (new AuthenticatorResolver())->for(new ServerConfig('a', [], transport: 'http', authType: 'bearer', bearerToken: 't0k'));

        self::assertInstanceOf(BearerTokenAuthenticator::class, $auth);
        self::assertSame(['Authorization' => 'Bearer t0k'], $auth->getHeaders(null));
    }

    #[Test]
    public function oneInstancePerServerPerRequest(): void
    {
        $resolver = new AuthenticatorResolver();
        $server = new ServerConfig('a', [], transport: 'http', authType: 'bearer', bearerToken: 't0k');

        self::assertSame($resolver->for($server), $resolver->for($server));
        self::assertNotSame($resolver->for($server), $resolver->for(new ServerConfig('b', [], transport: 'http')));
    }

    #[Test]
    public function oauthIsAnAuthenticationFailureUntilItExists(): void
    {
        $this->expectException(McpAuthenticationException::class);
        (new AuthenticatorResolver())->for(new ServerConfig('a', [], transport: 'http', authType: 'oauth'));
    }

    #[Test]
    public function anUnknownAuthTypeIsRejected(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('auth type "magic"');
        (new AuthenticatorResolver())->for(new ServerConfig('a', [], transport: 'http', authType: 'magic'));
    }
}
