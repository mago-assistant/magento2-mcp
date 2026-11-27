<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Worker;

use MagoAssistant\Mcp\Service\Worker\Handshake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    private const NONCE = '0123456789abcdef0123456789abcdef';

    #[Test]
    public function acceptsWhatItSigned(): void
    {
        $key = Handshake::key('crypt-key');
        $auth = Handshake::sign($key, self::NONCE, 'open', 'bricklayer');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $auth);
        self::assertTrue(Handshake::verify($key, self::NONCE, 'open', 'bricklayer', $auth));
    }

    #[Test]
    public function aSignatureIsBoundToNonceOperationServerAndKey(): void
    {
        $key = Handshake::key('crypt-key');
        $auth = Handshake::sign($key, self::NONCE, 'open', 'bricklayer');

        self::assertFalse(Handshake::verify($key, 'ffffffffffffffffffffffffffffffff', 'open', 'bricklayer', $auth));
        self::assertFalse(Handshake::verify($key, self::NONCE, 'stop', 'bricklayer', $auth));
        self::assertFalse(Handshake::verify($key, self::NONCE, 'open', 'magerun', $auth));
        self::assertFalse(Handshake::verify(Handshake::key('other-key'), self::NONCE, 'open', 'bricklayer', $auth));
    }

    #[Test]
    public function rejectsAnEmptyOrMalformedSignature(): void
    {
        $key = Handshake::key('crypt-key');

        self::assertFalse(Handshake::verify($key, self::NONCE, 'open', 'bricklayer', ''));
        self::assertFalse(Handshake::verify($key, self::NONCE, 'open', 'bricklayer', 'not-hex'));
        self::assertFalse(Handshake::verify($key, self::NONCE, 'open', 'bricklayer', str_repeat('A', 64)));
    }

    #[Test]
    public function differentCryptKeysGiveDifferentKeys(): void
    {
        self::assertNotSame(Handshake::key('a'), Handshake::key('b'));
    }

    #[Test]
    public function aRotatedCryptKeyUsesItsCurrentLastLine(): void
    {
        self::assertSame(Handshake::key('new-key'), Handshake::key("old-key\nnew-key"));
        self::assertSame(Handshake::key('new-key'), Handshake::key("old-key\nnew-key\n"));
    }

    #[Test]
    public function aNonceIsThirtyTwoHexCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Handshake::nonce());
        self::assertNotSame(Handshake::nonce(), Handshake::nonce());
    }
}
