<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Admin;

use Magento\Framework\Serialize\Serializer\Json;
use MagoAssistant\Mago\Logger\ErrorLogger;
use MagoAssistant\Mcp\Model\Config;
use MagoAssistant\Mcp\Service\Admin\ServerAdminService;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DefinitionRegistry;
use MagoAssistant\Mcp\Service\Transport\TransportResolver;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeCache;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeLogger;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeScopeConfig;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeServerRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\FakeTransport;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryClientRepository;
use MagoAssistant\Mcp\Test\Unit\Fakes\InMemoryTokenRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerAdminServiceTest extends TestCase
{
    private FakeServerRepository $servers;
    private InMemoryTokenRepository $tokens;
    private InMemoryClientRepository $clients;
    private FakeCache $cache;
    private ServerAdminService $service;

    protected function setUp(): void
    {
        $this->servers = new FakeServerRepository();
        $this->tokens = new InMemoryTokenRepository();
        $this->clients = new InMemoryClientRepository();
        $this->cache = new FakeCache();
        $catalog = new ToolCatalog(
            $this->servers,
            new TransportResolver(['stdio' => new FakeTransport(), 'http' => new FakeTransport()]),
            $this->cache,
            new Config(new FakeScopeConfig(['mago/mcp/enabled' => '1'])),
            new ModeClassifier(),
            new ErrorLogger(new FakeLogger(), new Json()),
            new DefinitionRegistry()
        );
        $this->service = new ServerAdminService($this->servers, $this->tokens, $this->clients, $catalog);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function post(array $overrides = []): array
    {
        return $overrides + ['label' => 'Docs Server', 'url' => 'https://mcp.example.com/mcp', 'auth_type' => 'none'];
    }

    private function connect(string $server, int $admin): void
    {
        $this->tokens->save($admin, $server, ['access_token' => 'token-' . $admin]);
        $this->clients->save($server, [
            'server_url' => 'https://mcp.example.com/mcp', 'redirect_uri' => 'https://shop.test/cb',
            'client_id' => 'c', 'client_secret' => null, 'metadata' => [],
        ]);
    }

    #[Test]
    public function createStoresAManualRowUnderTheDerivedName(): void
    {
        $name = $this->service->save(self::post(['enabled' => '1']), null);

        self::assertSame('docs_server', $name);
        self::assertSame('manual', $this->servers->rows['docs_server']['source']);
        self::assertTrue($this->servers->rows['docs_server']['enabled']);
    }

    #[Test]
    public function createRefusesANameThatExists(): void
    {
        $this->servers->add('docs_server', false, 'mcp_json');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $this->service->save(self::post(), null);
    }

    #[Test]
    public function invalidInputIsRefusedWithEveryReason(): void
    {
        try {
            $this->service->save(self::post(['label' => '', 'url' => 'http://public.example.com/mcp']), null);
            self::fail('invalid input must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('label', strtolower($e->getMessage()));
            self::assertStringContainsString('https', $e->getMessage());
        }
        self::assertSame([], $this->servers->rows);
    }

    #[Test]
    public function editKeepsTheStoredTokenWhenLeftBlank(): void
    {
        $this->service->save(self::post(['auth_type' => 'bearer', 'bearer_token' => 's3cret']), null);

        $this->service->save(self::post(['auth_type' => 'bearer', 'label' => 'Docs']), 'docs_server');

        self::assertSame('s3cret', $this->servers->rows['docs_server']['bearer_token']);
        self::assertSame('Docs', $this->servers->rows['docs_server']['label']);
    }

    #[Test]
    public function changingAnOauthUrlRemovesTokens(): void
    {
        $this->service->save(self::post(['auth_type' => 'oauth']), null);
        $this->connect('docs_server', 7);
        $this->connect('docs_server', 8);

        $this->service->save(self::post(['auth_type' => 'oauth', 'url' => 'https://other.example.com/mcp']), 'docs_server');

        self::assertNull($this->tokens->find(7, 'docs_server'), 'a token is never sent to the new host');
        self::assertNull($this->tokens->find(8, 'docs_server'));
        self::assertNull($this->clients->find('docs_server'), 'the client registration is redone on the next connect');
    }

    #[Test]
    public function savingAnOauthRowWithTheSameUrlKeepsTokens(): void
    {
        $this->service->save(self::post(['auth_type' => 'oauth']), null);
        $this->connect('docs_server', 7);

        $this->service->save(self::post(['auth_type' => 'oauth', 'label' => 'Docs']), 'docs_server');

        self::assertNotNull($this->tokens->find(7, 'docs_server'));
    }

    #[Test]
    public function deleteRemovesEveryAdminsTokensAndTheClient(): void
    {
        $this->service->save(self::post(['auth_type' => 'oauth']), null);
        $this->connect('docs_server', 7);
        $this->connect('docs_server', 8);

        $this->service->delete('docs_server');

        self::assertNull($this->servers->getByName('docs_server'));
        self::assertNull($this->tokens->find(7, 'docs_server'));
        self::assertNull($this->tokens->find(8, 'docs_server'));
        self::assertNull($this->clients->find('docs_server'));
    }

    #[Test]
    public function aDiscoveredRowIsNeitherDeletedNorFullyEdited(): void
    {
        $this->servers->add('docs', true, 'mcp_json', ['transport' => 'http', 'url' => 'https://a.test/mcp']);

        $this->service->save(['read_only' => '1', 'url' => 'https://evil.test/mcp', 'label' => 'X'], 'docs');

        self::assertTrue($this->servers->rows['docs']['read_only'], 'trust settings are the admin\'s');
        self::assertSame('https://a.test/mcp', $this->servers->rows['docs']['url'], 'connection details are the source\'s');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->delete('docs');
    }
}
