<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

/**
 * Turns a host-side "docker exec ... <container> <cmd>" entry into the command Mago's PHP can run
 * inside that container, keeping -w as the working directory and -e pairs as environment.
 */
class DockerExecUnwrapper
{
    private const BARE_FLAGS = ['-i', '-t', '-it', '-ti', '--interactive', '--tty', '--privileged', '-d', '--detach'];

    /**
     * @param string[] $args
     * @param array<string,string> $env
     * @return array{command: string[], env: array<string,string>, cwd: ?string}|null
     */
    public function unwrap(string $command, array $args, array $env): ?array
    {
        if ($command !== 'docker' || ($args[0] ?? '') !== 'exec') {
            return null;
        }
        $cwd = null;
        $i = 1;
        $count = count($args);
        while ($i < $count) {
            $arg = (string)$args[$i];
            if (in_array($arg, self::BARE_FLAGS, true)) {
                $i++;
                continue;
            }
            if ($arg === '-u' || $arg === '--user') {
                $i += 2;
                continue;
            }
            if ($arg === '-w' || $arg === '--workdir') {
                $cwd = isset($args[$i + 1]) ? (string)$args[$i + 1] : null;
                $i += 2;
                continue;
            }
            if ($arg === '-e' || $arg === '--env') {
                [$key, $value] = array_pad(explode('=', (string)($args[$i + 1] ?? ''), 2), 2, '');
                if ($key !== '') {
                    $env[$key] = $value;
                }
                $i += 2;
                continue;
            }
            if (str_starts_with($arg, '-')) {
                $i++;
                continue;
            }
            break; // the container name
        }
        $inner = array_values(array_map('strval', array_slice($args, $i + 1)));
        if ($inner === []) {
            return null;
        }

        return ['command' => $inner, 'env' => $env, 'cwd' => $cwd];
    }
}
