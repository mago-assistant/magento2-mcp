<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Admin;

use MagoAssistant\Mcp\Service\Admin\ServerInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerInputTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function post(array $overrides = []): array
    {
        return $overrides + [
            'label' => 'Docs Server',
            'url' => 'https://mcp.example.com/mcp',
            'auth_type' => 'none',
            'bearer_token' => '',
            'allowed_tools' => '',
            'timeout' => '',
            'replaces_skill' => '',
        ];
    }

    #[Test]
    public function aValidCreateBecomesAManualHttpRow(): void
    {
        $result = ServerInput::validate(self::post([
            'allowed_tools' => " query-docs \n\n resolve-library-id\n",
            'timeout' => '30',
            'output_public' => '1',
            'read_only' => '1',
            'enabled' => '1',
            'replaces_skill' => 'docs',
        ]), null);

        self::assertSame([], $result['errors']);
        $row = $result['row'];
        self::assertSame('docs_server', $row['name'], 'derived from the label, normalised');
        self::assertSame('Docs Server', $row['label']);
        self::assertSame('manual', $row['source']);
        self::assertSame('http', $row['transport']);
        self::assertSame([], $row['command']);
        self::assertSame('https://mcp.example.com/mcp', $row['url']);
        self::assertSame(['query-docs', 'resolve-library-id'], $row['allowed_tools']);
        self::assertSame(30, $row['timeout']);
        self::assertTrue($row['output_public']);
        self::assertTrue($row['read_only']);
        self::assertTrue($row['enabled']);
        self::assertSame('docs', $row['replaces_skill']);
        self::assertArrayNotHasKey('bearer_token', $row, 'no token given, none written');
    }

    #[Test]
    public function uncheckedBoxesAreFalse(): void
    {
        $row = ServerInput::validate(self::post(), null)['row'];

        self::assertFalse($row['output_public']);
        self::assertFalse($row['read_only']);
        self::assertFalse($row['enabled']);
        self::assertNull($row['timeout']);
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://mcp.example.com/mcp', true],
            'http localhost with port' => ['http://localhost:8000/mcp', true],
            'http loopback' => ['http://127.0.0.1/mcp', true],
            'http ipv6 loopback' => ['http://[::1]/mcp', true],
            'http .internal' => ['http://mcp.internal/mcp', true],
            'http .docker' => ['http://mcp.docker/mcp', true],
            'http .local' => ['http://box.local/mcp', true],
            'http .test' => ['http://mcp.test/mcp', true],
            'http public host' => ['http://mcp.example.com/mcp', false],
            'http host merely containing localhost' => ['http://localhost.example.com/mcp', false],
            'ftp' => ['ftp://mcp.example.com/mcp', false],
            'no host' => ['https:///mcp', false],
            'not a url' => ['mcp.example.com', false],
            'empty' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('urls')]
    public function urlMustBeHttpsUnlessLocal(string $url, bool $valid): void
    {
        $errors = ServerInput::validate(self::post(['url' => $url]), null)['errors'];

        self::assertSame($valid, $errors === [], $url . ' → ' . implode(' ', $errors));
    }

    #[Test]
    public function aLabelIsRequiredAndBounded(): void
    {
        self::assertNotSame([], ServerInput::validate(self::post(['label' => '  ']), null)['errors']);
        self::assertNotSame([], ServerInput::validate(self::post(['label' => str_repeat('x', 129)]), null)['errors']);
        self::assertNotSame([], ServerInput::validate(self::post(['label' => '!!!']), null)['errors'], 'nothing left to name it by');
    }

    #[Test]
    public function aBearerServerNeedsATokenOnCreateButKeepsItOnEdit(): void
    {
        self::assertNotSame([], ServerInput::validate(self::post(['auth_type' => 'bearer']), null)['errors']);

        $created = ServerInput::validate(self::post(['auth_type' => 'bearer', 'bearer_token' => ' s3cret ']), null);
        self::assertSame([], $created['errors']);
        self::assertSame('s3cret', $created['row']['bearer_token']);

        $existing = ['name' => 'docs_server', 'label' => 'Docs Server', 'source' => 'manual', 'auth_type' => 'bearer',
            'url' => 'https://mcp.example.com/mcp', 'bearer_token' => 's3cret'];
        $edited = ServerInput::validate(self::post(['auth_type' => 'bearer', 'label' => 'Renamed']), $existing);
        self::assertSame([], $edited['errors']);
        self::assertArrayNotHasKey('bearer_token', $edited['row'], 'blank on edit keeps the stored token');
        self::assertSame('docs_server', $edited['row']['name'], 'editing the label never renames');
        self::assertSame('Renamed', $edited['row']['label']);
    }

    #[Test]
    public function otherFieldsAreChecked(): void
    {
        self::assertNotSame([], ServerInput::validate(self::post(['auth_type' => 'magic']), null)['errors']);
        self::assertNotSame([], ServerInput::validate(self::post(['timeout' => '0']), null)['errors']);
        self::assertNotSame([], ServerInput::validate(self::post(['timeout' => '3601']), null)['errors']);
        self::assertNotSame([], ServerInput::validate(self::post(['timeout' => 'soon']), null)['errors']);
        self::assertNotSame([], ServerInput::validate(self::post(['replaces_skill' => 'Not Valid!']), null)['errors']);
    }

    #[Test]
    public function trustSettingsAreTheOnlyEditableFieldsOfADiscoveredRow(): void
    {
        $result = ServerInput::validateTrust(['read_only' => '1', 'url' => 'https://evil.test/mcp']);

        self::assertSame(['read_only' => true, 'output_public' => false], $result);
    }

    #[Test]
    public function aStoredTokenIsNotCarriedToANewHost(): void
    {
        $existing = ['name' => 'docs_server', 'source' => 'manual', 'auth_type' => 'bearer',
            'url' => 'https://mcp.example.com/mcp', 'bearer_token' => 's3cret'];

        $samePath = ServerInput::validate(self::post(['auth_type' => 'bearer', 'url' => 'https://mcp.example.com/v2']), $existing);
        $newHost = ServerInput::validate(self::post(['auth_type' => 'bearer', 'url' => 'https://attacker.example.net/mcp']), $existing);

        self::assertSame([], $samePath['errors'], 'the same host may keep its token');
        self::assertNotSame([], $newHost['errors'], 'a new host needs the token typed again');
    }

    #[Test]
    public function switchingToBearerNeedsAToken(): void
    {
        $existing = ['name' => 'docs_server', 'source' => 'manual', 'auth_type' => 'none',
            'url' => 'https://mcp.example.com/mcp', 'bearer_token' => ''];

        self::assertNotSame([], ServerInput::validate(self::post(['auth_type' => 'bearer']), $existing)['errors']);
    }
}
