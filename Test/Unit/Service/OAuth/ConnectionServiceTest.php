<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\OAuth;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use MagoAssistant\Mcp\Service\OAuth\ConnectionService;
use MagoAssistant\Mcp\Service\OAuth\OAuthClient;
use MagoAssistant\Mcp\Service\OAuth\OAuthException;
use MagoAssistant\Mcp\Service\ToolProvider;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeMcpServer;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryClientRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryPendingAuthorizationStore;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryTokenRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionServiceTest extends TestCase
{
    private const REDIRECT = 'https://shop.test/admin/magomcp/oauth/callback/';

    private InMemoryTokenRepository $tokens;
    private InMemoryClientRepository $clients;
    private InMemoryPendingAuthorizationStore $pending;
    private OAuthClient $oauth;
    private ConnectionService $service;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryTokenRepository();
        $this->clients = new InMemoryClientRepository();
        $this->oauth = $this->createMock(OAuthClient::class);

        $this->pending = new InMemoryPendingAuthorizationStore();

        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturn(self::REDIRECT);

        $provider = $this->createStub(ToolProvider::class);
        $provider->method('getServers')->willReturn(['test' => new FakeMcpServer()]);

        $this->service = new ConnectionService($this->clients, $this->tokens, $this->oauth, $provider, $this->pending, $url);
    }

    #[Test]
    public function registersOnceAndSendsAnS256ChallengeOfTheStoredVerifier(): void
    {
        $this->oauth->expects(self::once())->method('discover')->willReturn(['authorization_endpoint' => 'https://auth/a']);
        $this->oauth->expects(self::once())->method('register')->with(self::anything(), self::REDIRECT, 'Mago Assistant (shop.test)')
            ->willReturn(['client_id' => 'client-1', 'client_secret' => null]);
        $challenges = [];
        $this->oauth->method('authorizationUrl')->willReturnCallback(
            static function (array $metadata, array $client, string $redirect, string $challenge, string $state) use (&$challenges): string {
                $challenges[$state] = $challenge;
                return 'https://auth/a?state=' . $state;
            }
        );

        $this->service->start(new FakeMcpServer(), 1);
        $this->service->start(new FakeMcpServer(), 1);

        $pending = $this->pending->pending;
        self::assertCount(2, $pending);
        foreach ($pending as $state => $entry) {
            $expected = rtrim(strtr(base64_encode(hash('sha256', $entry['verifier'], true)), '+/', '-_'), '=');
            self::assertSame($expected, $challenges[$state]);
            self::assertSame(1, $entry['user']);
        }
    }

    #[Test]
    public function storesTheTokenForTheUserWhoStartedAndConsumesTheState(): void
    {
        $state = $this->startedState(1);
        $this->oauth->method('exchangeCode')->willReturn(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => null]);

        $this->service->complete($state, 'code-1', 1);

        self::assertSame('at', $this->tokens->find(1, 'test')['access_token']);
        $this->expectException(OAuthException::class);
        $this->service->complete($state, 'code-1', 1);
    }

    #[Test]
    public function rejectsACallbackForAnotherAdminUser(): void
    {
        $state = $this->startedState(1);
        $this->oauth->expects(self::never())->method('exchangeCode');

        $this->expectException(OAuthException::class);
        $this->service->complete($state, 'code-1', 2);
    }

    #[Test]
    public function rejectsAnUnknownState(): void
    {
        $this->expectException(OAuthException::class);
        $this->service->complete('forged', 'code-1', 1);
    }

    private function startedState(int $userId): string
    {
        $this->clients->save('test', [
            'server_url' => 'https://mcp.example.com/mcp',
            'redirect_uri' => self::REDIRECT,
            'client_id' => 'client-1',
            'client_secret' => null,
            'metadata' => [],
        ]);
        $this->oauth->method('authorizationUrl')->willReturn('https://auth/a');
        $this->service->start(new FakeMcpServer(), $userId);

        return (string)array_key_first($this->pending->pending);
    }
}
