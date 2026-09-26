<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Session as BackendSession;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Admin\ConnectionState;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;

/**
 * The add or edit form for one server. A manual row (or a new one) is editable in full; a discovered row
 * shows its connection details as text and lets the administrator change only its trust settings. Input
 * a failed save sent back is shown again instead of the stored values, once.
 */
class ServerForm extends Template
{
    public const FORM_DATA_KEY = 'mago_mcp_server_form';

    /** @var array<string,mixed>|null|false false until loaded */
    private array|null|false $row = false;

    /** @var array<string,mixed>|null */
    private ?array $formData = null;

    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ConnectionState $connectionState,
        private readonly BackendSession $backendSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<string,mixed>|null the stored row, or null for a new server
     */
    public function getRow(): ?array
    {
        if ($this->row === false) {
            $name = (string)$this->getRequest()->getParam('name', '');
            $this->row = $name === '' ? null : $this->servers->getByName($name);
        }

        return $this->row;
    }

    public function isNew(): bool
    {
        return $this->getRow() === null;
    }

    public function isManual(): bool
    {
        $row = $this->getRow();

        return $row === null || $row['source'] === DiscoveredServer::SOURCE_MANUAL;
    }

    public function getValue(string $key, mixed $default = ''): mixed
    {
        if ($this->formData === null) {
            $data = $this->backendSession->getData(self::FORM_DATA_KEY, true);
            $this->formData = is_array($data) ? $data : [];
        }
        if (array_key_exists($key, $this->formData)) {
            return $this->formData[$key];
        }
        $row = $this->getRow();
        if ($row === null || !array_key_exists($key, $row)) {
            return $default;
        }
        $value = $row[$key];
        if ($key === 'allowed_tools' && is_array($value)) {
            return implode("\n", $value);
        }
        if ($key === 'command' && is_array($value)) {
            return implode(' ', $value);
        }

        return $value;
    }

    public function isChecked(string $key): bool
    {
        $value = $this->getValue($key, false);

        return in_array(is_bool($value) ? ($value ? '1' : '0') : (string)$value, ['1', 'on', 'true'], true);
    }

    public function hasConnection(): bool
    {
        $row = $this->getRow();

        return $row !== null && $this->connectionState->hasConnection($row);
    }

    public function isConnected(): bool
    {
        $row = $this->getRow();

        return $row !== null && $this->connectionState->isConnected($row);
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/save');
    }

    public function getDeleteUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/delete');
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('mago_mcp/servers/index');
    }

    public function getConnectUrl(): string
    {
        return $this->getUrl('mago_mcp/oauth/connect', ['name' => (string)($this->getRow()['name'] ?? '')]);
    }

    public function getDisconnectUrl(): string
    {
        return $this->getUrl('mago_mcp/oauth/disconnect', ['name' => (string)($this->getRow()['name'] ?? '')]);
    }
}
