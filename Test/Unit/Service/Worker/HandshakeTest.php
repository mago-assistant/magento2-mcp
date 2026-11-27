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
    private const WN = '0123456789abcdef0123456789abcdef';
    private const CN = 'fedcba9876543210fedcba9876543210';

    #[Test]
    public function acceptsWhatItSignedAndNamesTheKey(): void
    {
        $key = Handshake::key('crypt-key');
        $auth = Handshake::signRequest($key, self::WN, self::CN, 'open', 'bricklayer', 60);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $auth);
        self::assertSame($key, Handshake::verifyRequest([$key], self::WN, self::CN, 'open', 'bricklayer', 60, $auth));
    }

    #[Test]
    public function aRequestIsBoundToBothNoncesOperationServerTimeoutAndKey(): void
    {
        $keys = Handshake::keys('crypt-key');
        $auth = Handshake::signRequest($keys[0], self::WN, self::CN, 'open', 'bricklayer', 60);

        self::assertNull(Handshake::verifyRequest($keys, str_repeat('f', 32), self::CN, 'open', 'bricklayer', 60, $auth));
        self::assertNull(Handshake::verifyRequest($keys, self::WN, str_repeat('0', 32), 'open', 'bricklayer', 60, $auth));
        self::assertNull(Handshake::verifyRequest($keys, self::WN, self::CN, 'stop', 'bricklayer', 60, $auth));
        self::assertNull(Handshake::verifyRequest($keys, self::WN, self::CN, 'open', 'magerun', 60, $auth));
        self::assertNull(Handshake::verifyRequest($keys, self::WN, self::CN, 'open', 'bricklayer', 3600, $auth));
        self::assertNull(Handshake::verifyRequest(Handshake::keys('other-key'), self::WN, self::CN, 'open', 'bricklayer', 60, $auth));
    }

    #[Test]
    public function rejectsAnEmptyOrMalformedSignature(): void
    {
        $keys = Handshake::keys('crypt-key');

        self::assertNull(Handshake::verifyRequest($keys, self::WN, self::CN, 'open', 'bricklayer', 60, ''));
        self::assertNull(Handshake::verifyRequest($keys, self::WN, self::CN, 'open', 'bricklayer', 60, 'not-hex'));
        self::assertNull(Handshake::verifyRequest($keys, self::WN, self::CN, 'open', 'bricklayer', 60, str_repeat('A', 64)));
        self::assertNull(Handshake::verifyRequest([''], self::WN, self::CN, 'open', 'x', 60, Handshake::signRequest('', self::WN, self::CN, 'open', 'x', 60)), 'an empty key is no key');
    }

    #[Test]
    public function aRotatedCryptKeyIsAcceptedUnderEveryLineAndSignedWithTheLast(): void
    {
        $rotated = "old-key\nnew-key\n";

        self::assertSame(Handshake::key('new-key'), Handshake::key($rotated));
        self::assertCount(2, Handshake::keys($rotated));
        $oldSigned = Handshake::signRequest(Handshake::key('old-key'), self::WN, self::CN, 'open', 's', 1);
        self::assertSame(Handshake::key('old-key'), Handshake::verifyRequest(Handshake::keys($rotated), self::WN, self::CN, 'open', 's', 1, $oldSigned));
        self::assertSame([], Handshake::keys(''));
        self::assertSame('', Handshake::key(''));
    }

    #[Test]
    public function theWorkersAnswerIsProvenAndCannotBeAltered(): void
    {
        $key = Handshake::key('crypt-key');
        $answer = Handshake::signAnswer($key, self::WN, self::CN, ['ok' => true, 'root' => '/var/www/é', 'pid' => 12]);

        self::assertTrue(Handshake::verifyAnswer($key, self::WN, self::CN, json_decode(json_encode($answer), true)));
        self::assertFalse(Handshake::verifyAnswer($key, self::WN, self::CN, ['pid' => 13] + $answer));
        self::assertFalse(Handshake::verifyAnswer($key, self::WN, str_repeat('0', 32), $answer));
        self::assertFalse(Handshake::verifyAnswer(Handshake::key('other'), self::WN, self::CN, $answer));
        self::assertFalse(Handshake::verifyAnswer($key, self::WN, self::CN, ['ok' => true]), 'no proof');
    }

    #[Test]
    public function aNonceIsThirtyTwoHexCharacters(): void
    {
        self::assertTrue(Handshake::isNonce(Handshake::nonce()));
        self::assertNotSame(Handshake::nonce(), Handshake::nonce());
        self::assertFalse(Handshake::isNonce('xyz'));
        self::assertFalse(Handshake::isNonce(['array']));
    }
}
