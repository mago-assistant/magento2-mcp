<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Api;

use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;

/**
 * Stored MCP servers as plain arrays with command, env and tool_overrides already decoded.
 *
 * @api
 */
interface ServerRepositoryInterface
{
    /** @return array<int,array<string,mixed>> */
    public function getAll(): array;

    /** @return array<int,array<string,mixed>> */
    public function getEnabled(): array;

    /** @return array<string,mixed>|null */
    public function getByName(string $name): ?array;

    /**
     * Insert, or update the row with the same name. Accepts the decoded shape getAll() returns.
     *
     * @param array<string,mixed> $row
     */
    public function save(array $row): void;

    public function setEnabled(string $name, bool $enabled): void;

    /** @param array<string,string> $overrides */
    public function setToolOverrides(string $name, array $overrides): void;

    public function setLastError(string $name, ?string $error): void;

    /**
     * Apply a scan. New servers insert disabled; known non-manual ones refresh their command; vanished
     * non-manual ones are flagged missing. Manual rows are untouched.
     *
     * @param DiscoveredServer[] $discovered
     * @return array{inserted:int, updated:int, missing:int}
     */
    public function merge(array $discovered): array;
}
