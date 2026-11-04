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
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\DataAcl;
use MagoAssistant\Mcp\Service\Catalog\ModeClassifier;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;
use MagoAssistant\Mcp\Service\Discovery\DiscoveredServer;
use MagoAssistant\Mcp\Service\Tool\Mcp\McpSkill;

/**
 * The add or edit page for one server, laid out like Mago's skill edit page: an information table, the
 * scanned tools as a grid of checkboxes (what the server may expose), the trust settings, the actions.
 * A manual row (or a new one) is editable in full; a discovered row shows its connection details as
 * text. Input a failed save sent back is shown again instead of the stored values, once.
 */
class ServerForm extends Template
{
    public const FORM_DATA_KEY = 'mago_mcp_server_form';

    /** @var array<string,mixed>|null|false false until loaded */
    private array|null|false $row = false;

    /** @var array<string,mixed>|null */
    private ?array $formData = null;

    /** @var array<int,array<string,mixed>>|null */
    private ?array $toolRows = null;

    public function __construct(
        Context $context,
        private readonly ServerRepositoryInterface $servers,
        private readonly ConnectionState $connectionState,
        private readonly BackendSession $backendSession,
        private readonly ToolCatalog $catalog,
        private readonly DataAcl $dataAcl,
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
        if ($key === 'command' && is_array($value)) {
            return implode(' ', $value);
        }

        return $value;
    }

    public function isChecked(string $key): bool
    {
        $this->getValue($key);
        if ($this->formData !== []) {
            // A failed save sent the form back: an unticked box is simply absent from it, and must stay
            // unticked rather than fall back to the stored value.
            $value = $this->formData[$key] ?? false;

            return in_array((string)$value, ['1', 'on', 'true'], true);
        }
        $value = $this->getValue($key, false);

        return in_array(is_bool($value) ? ($value ? '1' : '0') : (string)$value, ['1', 'on', 'true'], true);
    }

    /**
     * The scanned tools, one row each, grouped for the template: reads, then writes, then the execution
     * and credential tools, each with whether it is currently exposed and the Magento resource it needs.
     * Empty for a new server or one whose list could not be fetched (see getToolsError()).
     *
     * Keys: tool, skill, group (read, write or execution), type, mode_origin, exposed, withheld_by_default,
     * resource, description, personal_data, permissions_url.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getToolRows(): array
    {
        if ($this->toolRows !== null) {
            return $this->toolRows;
        }
        $row = $this->getRow();
        if ($row === null) {
            return $this->toolRows = [];
        }
        $allowed = $this->allowedNames();
        $explicit = $allowed !== null;
        $rows = [];
        foreach ($this->catalog->entriesForServer((string)$row['name'], $this->connectionState->currentAdminId()) as $entry) {
            $dangerous = $entry->executionSurface || $entry->modeOrigin === CatalogEntry::ORIGIN_SENSITIVE;
            $rows[] = [
                'tool' => $entry->tool,
                'skill' => McpSkill::nameFor($entry->server, $entry->tool),
                'group' => $dangerous ? 'execution' : $entry->mode,
                'type' => $entry->mode,
                'mode_origin' => $entry->modeOrigin,
                'exposed' => $explicit ? in_array($entry->tool, $allowed, true) : !$entry->withheld,
                'withheld_by_default' => $dangerous,
                'resource' => $this->dataAcl->resourceFor($entry->tool),
                'description' => $this->firstSentence($entry->description),
                'personal_data' => $entry->personalData,
                'permissions_url' => $this->getUrl('mago/skills/edit', ['skill_name' => McpSkill::nameFor($entry->server, $entry->tool)]),
            ];
        }
        $order = ['read' => 0, 'write' => 1, 'execution' => 2];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['group']], $a['tool']] <=> [$order[$b['group']], $b['tool']]);

        return $this->toolRows = $rows;
    }

    /**
     * @return array<string,array{title:string,note:string}>
     */
    public function getToolGroups(): array
    {
        return [
            ModeClassifier::READ => [
                'title' => (string)__('Reads'),
                'note' => (string)__('Run at once, without the confirmation card.'),
            ],
            ModeClassifier::WRITE => [
                'title' => (string)__('Writes'),
                'note' => (string)__('Go through the confirmation card before they run.'),
            ],
            'execution' => [
                'title' => (string)__('Execution and credential tools'),
                'note' => (string)__('Run code, SQL or commands on the store, or deal in credentials. Withheld until ticked here; once ticked, only roles holding MCP Tools - Execute get them, behind an acknowledged card.'),
            ],
        ];
    }

    /**
     * Why there are no tool rows: the row's last error, or that the server has not been contacted yet.
     */
    public function getToolsError(): string
    {
        $row = $this->getRow();
        if ($row === null) {
            return (string)__('Save the server first; its tools are fetched on the next visit to this page.');
        }
        $error = (string)($row['last_error'] ?? '');

        return $error !== '' ? $error : (string)__('The server returned no tools.');
    }

    public function countExposed(): int
    {
        return count(array_filter($this->getToolRows(), static fn (array $r): bool => $r['exposed']));
    }

    /**
     * The stored allowed list, or null when it is empty (every tool except the withheld ones).
     *
     * @return string[]|null
     */
    private function allowedNames(): ?array
    {
        $value = $this->getValue('allowed_tools', []);
        if (is_string($value)) {
            $value = preg_split('/\R/', $value) ?: [];
        }
        $names = array_values(array_filter(array_map('trim', array_map('strval', is_array($value) ? $value : [])), static fn (string $n): bool => $n !== ''));

        return $names === [] ? null : $names;
    }

    private function firstSentence(string $description): string
    {
        $first = preg_split('/(?<=[.!?])\s+/', trim($description), 2)[0] ?? '';

        return mb_strlen($first) > 160 ? rtrim(mb_substr($first, 0, 159)) . '…' : $first;
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
        return $this->getUrl('mago_mcp/servers/delete', ['name' => (string)($this->getRow()['name'] ?? '')]);
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
