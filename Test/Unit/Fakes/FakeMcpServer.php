<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use MagoAssistant\Mcp\Api\AuthenticatorInterface;
use MagoAssistant\Mcp\Api\ServerInterface;
use MagoAssistant\Mcp\Service\Auth\BearerTokenAuthenticator;

final class FakeMcpServer implements ServerInterface
{
    /**
     * @param string[] $allowedTools
     * @param array<string, array{0: string, 1?: string}> $classification
     */
    public function __construct(
        private readonly string $code = 'test',
        private readonly ?AuthenticatorInterface $authenticator = null,
        private readonly array $allowedTools = [],
        private readonly array $classification = []
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return 'Test Server';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getUrl(): string
    {
        return 'https://mcp.example.com/mcp';
    }

    public function getAuthenticator(): AuthenticatorInterface
    {
        return $this->authenticator ?? new BearerTokenAuthenticator('secret');
    }

    public function getTimeout(): int
    {
        return 5;
    }

    public function getAllowedTools(): array
    {
        return $this->allowedTools;
    }

    public function getFieldClassification(string $toolName): array
    {
        return $this->classification;
    }
}
