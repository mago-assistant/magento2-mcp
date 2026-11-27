#!/usr/bin/env php
<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

// Listens like the MCP worker without knowing the install's key: sends a nonce, answers every request with
// {"ok":true}, and writes whatever else a client sends to the file named by the second argument.
$server = stream_socket_server('tcp://127.0.0.1:0');
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
while ($client = stream_socket_accept($server, 30)) {
    fwrite($client, json_encode(['v' => 2, 'nonce' => bin2hex(random_bytes(16))]) . "\n");
    fgets($client);
    fwrite($client, json_encode(['ok' => true, 'root' => '/fake', 'build' => 'fake', 'pid' => 1, 'connections' => 0, 'started' => 0, 'proof' => str_repeat('0', 64)]) . "\n");
    stream_set_timeout($client, 2);
    $rest = stream_get_contents($client);
    file_put_contents($argv[1], (string)$rest, FILE_APPEND);
    fclose($client);
}
