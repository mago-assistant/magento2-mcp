<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\OAuth;

use Magento\Backend\Model\Auth\Session as AuthSession;

class CurrentAdminUser
{
    public function __construct(
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * The logged-in admin user's id, or null outside an admin session (CLI, cron)
     */
    public function getId(): ?int
    {
        $userId = (int)$this->authSession->getUser()?->getId();

        return $userId > 0 ? $userId : null;
    }
}
