<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use MagoAssistant\Mcp\Api\AuthenticatorInterface;
use MagoAssistant\Mcp\Api\ServerInterface;
use MagoAssistant\Mcp\Service\Auth\BearerTokenAuthenticator;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * An MCP server configured in Stores > Configuration. Another server is a virtualType with its own
 * code and config group (same field ids) plus a system.xml group for it.
 */
class ConfiguredServer implements ServerInterface
{
    private const DEFAULT_TIMEOUT = 20;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly string $code = 'custom',
        private readonly string $configPath = 'mago/mcp'
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        $label = trim($this->value('label'));
        return $label !== '' ? $label : $this->code;
    }

    public function isEnabled(): bool
    {
        return $this->value('enabled') === '1' && $this->getUrl() !== '';
    }

    public function getUrl(): string
    {
        return trim($this->value('url'));
    }

    public function getAuthenticator(): AuthenticatorInterface
    {
        $token = $this->value('token');

        return new BearerTokenAuthenticator($token !== '' ? trim($this->encryptor->decrypt($token)) : '');
    }

    public function getTimeout(): int
    {
        $timeout = (int)$this->value('timeout');
        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
    }

    public function getAllowedTools(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->value('allowed_tools')))));
    }

    public function getFieldClassification(string $toolName): array
    {
        // The admin asserts per server that its output holds no personal data; otherwise fail closed.
        return $this->value('output_public') === '1' ? [PiiClass::ANY => [PiiClass::PUBLIC]] : [];
    }

    private function value(string $field): string
    {
        return (string)$this->scopeConfig->getValue($this->configPath . '/' . $field);
    }
}
