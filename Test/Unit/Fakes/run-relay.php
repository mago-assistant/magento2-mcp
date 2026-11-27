#!/usr/bin/env php
<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

// Runs a Relay for RelayTest. Argument: a JSON file with key, root, max, handshake and servers
// (name => command, env, timeout). Prints the bound address on the first line, then serves.

use MagoAssistant\Mcp\Service\Mcp\ServerConfig;
use MagoAssistant\Mcp\Service\Worker\Handshake;
use MagoAssistant\Mcp\Service\Worker\Relay;

require __DIR__ . '/../../bootstrap.php';

$cfg = json_decode((string)file_get_contents($argv[1]), true);
$resolver = static function (string $name) use ($cfg): ?ServerConfig {
    $server = $cfg['servers'][$name] ?? null;

    return is_array($server)
        ? new ServerConfig($name, $server['command'], $server['env'] ?? [], timeout: $server['timeout'] ?? 10)
        : null;
};
$log = static function (string $message): void {
    fwrite(STDERR, date('H:i:s') . ' ' . $message . "\n");
};
$relay = new Relay(
    $resolver,
    static fn (): array => Handshake::keys($cfg['key']),
    $cfg['root'],
    $cfg['max'] ?? 8,
    $log,
    $cfg['handshake'] ?? 5.0,
    $cfg['build'] ?? ''
);
fwrite(STDOUT, $relay->listen('127.0.0.1:0') . "\n");
fflush(STDOUT);
$relay->run();
