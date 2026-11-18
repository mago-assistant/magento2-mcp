<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Service\Privacy;

use MagoAssistant\Mcp\Service\Privacy\SecretScrub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecretScrubTest extends TestCase
{
    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function texts(): iterable
    {
        yield 'db info table' => ["host     | db.internal\npassword | Sup3r-s3cret!\nusername | shop", "host     | db.internal\npassword | [secret]\nusername | shop"];
        yield 'json' => ['{"username":"shop","password":"p4ss","db":"m2"}', '{"username":"shop","password":"[secret]","db":"m2"}'];
        yield 'key = value' => ['api_key = sk_live_abc123', 'api_key = [secret]'];
        yield 'config path row' => ['payment/gateway/api_key | sk_live_abc123', 'payment/gateway/api_key | [secret]'];
        yield 'encrypted blob' => ['value: 0:3:aGVsbG8gd29ybGQgdGhpcyBpcyBiYXNlNjQ=', 'value: [secret]'];
        yield 'bearer header' => ['Authorization: Bearer eyJhbGciOi.payload.sig', 'Authorization: Bearer [secret]'];
        yield 'pdo dsn' => ['dsn: mysql:host=db;dbname=m2;password=p4ss', 'dsn: [secret]'];
        yield 'jdbc url' => ['jdbc:mysql://shop:p4ss@db.internal:3306/m2', 'jdbc:mysql://shop:[secret]@db.internal:3306/m2'];
        yield 'mysql cli' => ["mysql -h db -u shop -p'p4ss' m2", "mysql -h db -u shop -p'[secret]' m2"];
        yield 'admin url key' => ['https://shop.test/admin/sales/order/view/order_id/1/key/0123456789abcdef0123456789abcdef/', 'https://shop.test/admin/sales/order/view/order_id/1/key/[secret]/'];
        yield 'plain prose stays' => ['The customer asked about the token ring in the catalog.', 'The customer asked about the token ring in the catalog.'];
        yield 'password word without value stays' => ['Reset your password on the account page.', 'Reset your password on the account page.'];
    }

    #[Test]
    #[DataProvider('texts')]
    public function masksCredentialsAndLeavesProse(string $text, string $expected): void
    {
        self::assertSame($expected, (new SecretScrub())->mask($text));
    }

    #[Test]
    public function masksEveryStringLeafOfData(): void
    {
        $data = ['connection' => ['host' => 'db', 'password' => 'p4ss', 'nested' => ['token: abc']], 'count' => 3];

        self::assertSame(
            ['connection' => ['host' => 'db', 'password' => 'p4ss', 'nested' => ['token: [secret]']], 'count' => 3],
            (new SecretScrub())->maskLeaves($data),
            'a leaf is masked by its own content; a key named password with a bare value is Mago\'s field rule to catch'
        );
        self::assertTrue((new SecretScrub())->containsSecret('password: x'));
        self::assertFalse((new SecretScrub())->containsSecret('nothing here'));
    }
}
