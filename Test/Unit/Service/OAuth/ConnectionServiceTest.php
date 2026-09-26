<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\OAuth;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\OAuth\ConnectionService;
use MagoAssistant\Mcp\Service\OAuth\OAuthClient;
use MagoAssistant\Mcp\Service\OAuth\OAuthException;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryClientRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryPendingAuthorizationStore;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryTokenRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionServiceTest extends TestCase
{
    private const REDIRECT = 'https://shop.test/admin/mago_mcp/oauth/callback/';

    private InMemoryTokenRepository $tokens;
    private InMemoryClientRepository $clients;
    private InMemoryPendingAuthorizationStore $pending;
    private FakeServerRepository $servers;
    private OAuthClient $oauth;
    private ConnectionService $service;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryTokenRepository();
        $this->clients = new InMemoryClientRepository();
        $this->pending = new InMemoryPendingAuthorizationStore();
        $this->servers = new FakeServerRepository();
        $this->servers->add('test', true, 'module', $this->oauthRow());
        $this->oauth = $this->createStub(OAuthClient::class);
        $this->build();
    }

    /**
     * Builds the service over the current $this->oauth double
     */
    private function build(): void
    {
        $url = $this->createStub(BackendUrl::class);
        $url->method('getUrl')->willReturn(self::REDIRECT);

        $this->service = new ConnectionService(
            $this->clients,
            $this->tokens,
            $this->oauth,
            $this->catalog(),
            $this->pending,
            $url
        );
    }

    #[Test]
    public function registersOnceAndSendsAnS256ChallengeOfTheStoredVerifier(): void
    {
        $this->oauth = $this->createMock(OAuthClient::class);
        $this->build();
        $this->oauth->expects(self::once())->method('discover')
            ->willReturn(['authorization_endpoint' => 'https://auth/a']);
        $this->oauth->expects(self::once())->method('register')
            ->with(self::anything(), self::REDIRECT, 'Mago Assistant (shop.test)')
            ->willReturn(['client_id' => 'client-1', 'client_secret' => null]);
        $challenges = [];
        $this->oauth->method('authorizationUrl')->willReturnCallback(
            static function (
                array $metadata,
                array $client,
                string $redirect,
                string $challenge,
                string $state
            ) use (&$challenges): string {
                $challenges[$state] = $challenge;
                return 'https://auth/a?state=' . $state;
            }
        );

        $this->service->start($this->server(), 1);
        $this->service->start($this->server(), 1);

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
        $this->oauth->method('exchangeCode')
            ->willReturn(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600, 'scope' => null]);

        $server = $this->service->complete($state, 'code-1', 1);

        self::assertSame('test', $server->name);
        self::assertSame('at', $this->tokens->find(1, 'test')['access_token']);
        $this->expectException(OAuthException::class);
        $this->service->complete($state, 'code-1', 1);
    }

    #[Test]
    public function rejectsACallbackForAnotherAdminUser(): void
    {
        $this->oauth = $this->createMock(OAuthClient::class);
        $this->build();
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

    #[Test]
    public function getServerReturnsAnEnabledOauthRow(): void
    {
        $server = $this->service->getServer('test');

        self::assertSame('test', $server->name);
        self::assertSame(ServerConfig::TRANSPORT_HTTP, $server->transport);
        self::assertSame(ServerConfig::AUTH_OAUTH, $server->authType);
        self::assertSame('https://mcp.example.com/mcp', $server->url);
    }

    #[Test]
    public function getServerRejectsADisabledOrNonOauthRow(): void
    {
        $this->servers->add('off', false, 'module', $this->oauthRow());
        $this->servers->add('bearer', true, 'module', ['auth_type' => 'bearer'] + $this->oauthRow());

        $thrown = [];
        foreach (['off', 'bearer', 'unknown'] as $name) {
            try {
                $this->service->getServer($name);
            } catch (OAuthException $e) {
                $thrown[] = $name;
            }
        }

        self::assertSame(['off', 'bearer', 'unknown'], $thrown);
    }

    #[Test]
    public function disconnectRemovesTheTokenAndIsConnectedFollowsIt(): void
    {
        $this->tokens->save(1, 'test', ['access_token' => 'at', 'refresh_token' => null, 'expires_in' => 0]);
        self::assertTrue($this->service->isConnected($this->server(), 1));
        self::assertFalse($this->service->isConnected($this->server(), 2));

        $this->service->disconnect($this->server(), 1);

        self::assertFalse($this->service->isConnected($this->server(), 1));
    }

    private function server(): ServerConfig
    {
        return new ServerConfig(
            'test',
            [],
            transport: ServerConfig::TRANSPORT_HTTP,
            url: 'https://mcp.example.com/mcp',
            authType: ServerConfig::AUTH_OAUTH,
            label: 'Test Server'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function oauthRow(): array
    {
        return [
            'transport' => 'http',
            'auth_type' => 'oauth',
            'url' => 'https://mcp.example.com/mcp',
            'command' => [],
            'label' => 'Test Server',
        ];
    }

    private function catalog(): ToolCatalog
    {
        $config = new Config(new FakeScopeConfig([
            'mago/mcp/enabled' => '1',
            'mago/mcp/process_timeout' => '5',
            'mago/mcp/cache_lifetime' => '60',
        ]));

        return new ToolCatalog(
            $this->servers,
            new TransportResolver(['stdio' => new FakeTransport()]),
            new FakeCache(),
            $config,
            new ModeClassifier(),
            new ErrorLogger(new FakeLogger(), new Json()),
            new DefinitionRegistry()
        );
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
        $this->service->start($this->server(), $userId);

        return (string)array_key_first($this->pending->pending);
    }
}
