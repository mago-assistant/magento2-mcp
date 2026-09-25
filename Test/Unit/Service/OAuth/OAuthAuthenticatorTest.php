<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\OAuth;

use MagoAssistant\Mcp\Service\OAuth\CurrentAdminUser;
use MagoAssistant\Mcp\Service\OAuth\OAuthAuthenticator;
use MagoAssistant\Mcp\Service\OAuth\OAuthClient;
use MagoAssistant\Mcp\Service\OAuth\OAuthException;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryClientRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryTokenRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthAuthenticatorTest extends TestCase
{
    private InMemoryTokenRepository $tokens;
    private InMemoryClientRepository $clients;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryTokenRepository();
        $this->clients = new InMemoryClientRepository();
        $this->clients->save('rumvision', [
            'server_url' => 'https://mcp.example.com/mcp',
            'redirect_uri' => 'https://shop.test/cb',
            'client_id' => 'client-1',
            'client_secret' => null,
            'metadata' => ['token_endpoint' => 'https://auth.example.com/token'],
        ]);
    }

    #[Test]
    public function sendsOnlyTheRequestingUsersOwnToken(): void
    {
        $this->tokens->save(1, 'rumvision', ['access_token' => 'token-of-1', 'expires_in' => 3600]);
        $authenticator = $this->authenticator();

        self::assertSame(['Authorization' => 'Bearer token-of-1'], $authenticator->getHeaders(1));
        self::assertSame([], $authenticator->getHeaders(2));
        self::assertTrue($authenticator->hasCredentials(1));
        self::assertFalse($authenticator->hasCredentials(2));
    }

    #[Test]
    public function usesTheLoggedInAdminWhenNoUserIsPassed(): void
    {
        $this->tokens->save(5, 'rumvision', ['access_token' => 'token-of-5']);

        self::assertSame(['Authorization' => 'Bearer token-of-5'], $this->authenticator(null, 5)->getHeaders(null));
        self::assertSame([], $this->authenticator(null, null)->getHeaders(null), 'no session user, no token');
    }

    #[Test]
    public function refreshesATokenThatIsAboutToExpire(): void
    {
        $this->tokens->save(1, 'rumvision', ['access_token' => 'old', 'refresh_token' => 'rt', 'expires_in' => 10]);
        $oauth = $this->createMock(OAuthClient::class);
        $oauth->expects(self::once())->method('refresh')->with(self::anything(), self::anything(), 'rt')
            ->willReturn(['access_token' => 'new', 'refresh_token' => 'rt2', 'expires_in' => 3600, 'scope' => null]);

        self::assertSame(['Authorization' => 'Bearer new'], $this->authenticator($oauth)->getHeaders(1));
        self::assertSame('rt2', $this->tokens->find(1, 'rumvision')['refresh_token']);
    }

    #[Test]
    public function disconnectsTheUserWhenRefreshFails(): void
    {
        $this->tokens->save(1, 'rumvision', ['access_token' => 'old', 'refresh_token' => 'rt', 'expires_in' => 3600]);
        $oauth = $this->createStub(OAuthClient::class);
        $oauth->method('refresh')->willThrowException(new OAuthException('invalid_grant'));

        self::assertFalse($this->authenticator($oauth)->onUnauthorized(1));
        self::assertNull($this->tokens->find(1, 'rumvision'));
    }

    #[Test]
    public function retriesAfter401WhenTheRefreshWorked(): void
    {
        $this->tokens->save(1, 'rumvision', ['access_token' => 'old', 'refresh_token' => 'rt', 'expires_in' => 3600]);
        $oauth = $this->createStub(OAuthClient::class);
        $oauth->method('refresh')->willReturn(['access_token' => 'new', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => null]);

        self::assertTrue($this->authenticator($oauth)->onUnauthorized(1));
        self::assertFalse($this->authenticator($oauth)->onUnauthorized(2), 'another user has nothing to refresh');
    }

    private function authenticator(?OAuthClient $oauth = null, ?int $sessionUserId = null): OAuthAuthenticator
    {
        $currentUser = $this->createStub(CurrentAdminUser::class);
        $currentUser->method('getId')->willReturn($sessionUserId);

        return new OAuthAuthenticator(
            'rumvision',
            $this->tokens,
            $this->clients,
            $oauth ?? $this->createStub(OAuthClient::class),
            $currentUser
        );
    }
}
