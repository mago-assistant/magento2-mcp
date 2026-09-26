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
use MagoAssistant\Mcp\Service\OAuth\OAuthAuthenticator;
use MagoAssistant\Mcp\Service\OAuth\OAuthAuthenticatorFactory;
use MagoAssistant\Mcp\Service\OAuth\OAuthClient;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryClientRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryTokenRepository;
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
    public function oauthResolvesThroughTheFactoryAndFailsWithoutOne(): void
    {
        $factory = new OAuthAuthenticatorFactory(
            new InMemoryTokenRepository(),
            new InMemoryClientRepository(),
            $this->createStub(OAuthClient::class)
        );
        $server = new ServerConfig('a', [], transport: 'http', authType: 'oauth');

        self::assertInstanceOf(OAuthAuthenticator::class, (new AuthenticatorResolver($factory))->for($server));

        $this->expectException(McpAuthenticationException::class);
        (new AuthenticatorResolver())->for($server);
    }

    #[Test]
    public function anUnknownAuthTypeIsRejected(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('auth type "magic"');
        (new AuthenticatorResolver())->for(new ServerConfig('a', [], transport: 'http', authType: 'magic'));
    }
}
