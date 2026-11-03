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
use MagoAssistant\Mcp\Model\Config\Source\AuthType;
use MagoAssistant\Mcp\Service\Auth\BearerTokenAuthenticator;
use MagoAssistant\Mcp\Service\OAuth\OAuthAuthenticatorFactory;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * An MCP server configured in Stores > Configuration. Another server is a virtualType with its own
 * code and config group (same field ids) plus a system.xml group for it.
 */
class ConfiguredServer implements ServerInterface
{
    private const DEFAULT_TIMEOUT = 20;

    private ?AuthenticatorInterface $authenticator = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param OAuthAuthenticatorFactory $oauthAuthenticatorFactory
     * @param string $code
     * @param string $configPath
     * @param array<string, array<string, array{0: string, 1?: string}>> $classificationOverrides
     *        Per remote tool, replaces the server-wide classification when output is marked public
     * @param array<string, string> $errorHints Error text fragment => hint appended to that error
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly OAuthAuthenticatorFactory $oauthAuthenticatorFactory,
        private readonly string $code = 'custom',
        private readonly string $configPath = 'mago_mcp/custom',
        private readonly array $classificationOverrides = [],
        private readonly array $errorHints = []
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

    public function isOAuth(): bool
    {
        return $this->value('auth_type') === AuthType::OAUTH;
    }

    public function getAuthenticator(): AuthenticatorInterface
    {
        if ($this->authenticator === null) {
            $token = $this->value('token');
            $this->authenticator = $this->isOAuth()
                ? $this->oauthAuthenticatorFactory->create($this->code)
                : new BearerTokenAuthenticator($token !== '' ? trim($this->encryptor->decrypt($token)) : '');
        }
        return $this->authenticator;
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
        if ($this->value('output_public') !== '1') {
            return [];
        }
        return $this->classificationOverrides[$toolName] ?? [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getReplacesSkill(): string
    {
        return trim($this->value('replaces_skill'));
    }

    public function getErrorHint(string $toolName, string $error): string
    {
        foreach ($this->errorHints as $fragment => $hint) {
            if (str_contains($error, (string)$fragment)) {
                return $hint;
            }
        }
        return '';
    }

    private function value(string $field): string
    {
        return (string)$this->scopeConfig->getValue($this->configPath . '/' . $field);
    }
}
