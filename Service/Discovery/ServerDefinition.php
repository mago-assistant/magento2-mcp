<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

/**
 * A server a module ships, built by di.xml. Only its storable fields reach the table; the per-tool
 * field classification and the error hints are read from the definition each time the server is used.
 */
final class ServerDefinition
{
    public readonly string $name;

    /**
     * @param string[] $command
     * @param array<string,string> $env
     * @param string[] $allowedTools
     * @param array<string,array<string,array{0:string,1?:string}>> $fieldClassificationOverrides per tool
     * @param array<string,string> $errorHints
     */
    public function __construct(
        string $name,
        public readonly string $label = '',
        public readonly string $transport = 'http',
        public readonly string $url = '',
        public readonly array $command = [],
        public readonly array $env = [],
        public readonly ?string $cwd = null,
        public readonly string $authType = 'none',
        public readonly string $bearerToken = '',
        public readonly array $allowedTools = [],
        public readonly ?int $timeout = null,
        public readonly bool $outputPublic = false,
        public readonly string $replacesSkill = '',
        public readonly array $fieldClassificationOverrides = [],
        public readonly array $errorHints = [],
        public readonly bool $readOnly = false
    ) {
        $this->name = DiscoveredServer::normaliseName($name);
    }

    public function toDiscoveredServer(): DiscoveredServer
    {
        return new DiscoveredServer(
            $this->name,
            array_values(array_map('strval', $this->command)),
            array_map('strval', $this->env),
            $this->cwd,
            DiscoveredServer::SOURCE_MODULE,
            $this->label,
            $this->transport,
            $this->url,
            $this->authType,
            $this->bearerToken,
            array_values(array_map('strval', $this->allowedTools)),
            $this->timeout !== null && $this->timeout > 0 ? (int)$this->timeout : null,
            $this->outputPublic,
            $this->replacesSkill,
            $this->readOnly
        );
    }
}
