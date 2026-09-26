<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Test\Unit\Fakes;

use Magento\Framework\AuthorizationInterface;

/**
 * The admin role's ACL without Magento's ACL builder: only the listed resource ids are allowed.
 */
final class FakeAuthorization implements AuthorizationInterface
{
    /** @var string[] */
    public array $asked = [];

    /**
     * @param string[] $allowed
     */
    public function __construct(private readonly array $allowed = [])
    {
    }

    /**
     * @param string $resource
     * @param string|null $privilege
     * @return bool
     */
    public function isAllowed($resource, $privilege = null): bool
    {
        $this->asked[] = (string)$resource;

        return in_array($resource, $this->allowed, true);
    }
}
