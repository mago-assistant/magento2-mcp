<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Ui\DataProvider;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\ReportingInterface;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;
use MagoAssistant\Mcp\Api\ServerRepositoryInterface;
use MagoAssistant\Mcp\Service\Admin\ConnectionState;
use MagoAssistant\Mcp\Service\Catalog\CatalogEntry;
use MagoAssistant\Mcp\Service\Catalog\ToolCatalog;

/**
 * The servers grid, built the way Mago's Skills & Permissions grid is: rows from memory, one document per
 * server. Tool lists are read first so a fresh last_error written by a failed fetch is in the rows.
 */
class ServersDataProvider extends DataProvider
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $rows = null;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        ReportingInterface $reporting,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RequestInterface $request,
        FilterBuilder $filterBuilder,
        private readonly ServerRepositoryInterface $servers,
        private readonly ToolCatalog $catalog,
        private readonly ConnectionState $connectionState,
        private readonly SearchResultFactory $searchResultFactory,
        private readonly DocumentFactory $documentFactory,
        private readonly AttributeValueFactory $attributeValueFactory,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $reporting,
            $searchCriteriaBuilder,
            $request,
            $filterBuilder,
            $meta,
            $data
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $adminUserId = $this->connectionState->currentAdminId();
        $tools = [];
        foreach ($this->servers->getEnabled() as $row) {
            $entries = $this->catalog->entriesForServer((string)$row['name'], $adminUserId);
            $withheld = count(array_filter($entries, static fn (CatalogEntry $e): bool => $e->withheld));
            $tools[$row['name']] = [count($entries) - $withheld, $withheld];
        }
        $this->rows = [];
        foreach ($this->servers->getAll() as $row) {
            [$exposed, $withheld] = $tools[$row['name']] ?? [0, 0];
            $this->rows[] = [
                'name' => (string)$row['name'],
                'label' => (string)($row['label'] !== '' ? $row['label'] : $row['name']),
                'source' => (string)$row['source'],
                'transport' => (string)$row['transport'],
                'auth_type' => (string)$row['auth_type'],
                'endpoint' => $row['transport'] === 'http' ? (string)$row['url'] : implode(' ', $row['command']),
                'enabled' => $row['enabled'] ? '1' : '0',
                'tools' => $row['enabled'] ? $this->toolsText($exposed, $withheld) : '',
                'status' => $this->statusText($row),
                'missing' => $row['missing'] ? '1' : '0',
                'has_connection' => $this->connectionState->hasConnection($row) ? '1' : '0',
                'connected' => $this->connectionState->isConnected($row) ? '1' : '0',
            ];
        }

        return $this->rows;
    }

    private function toolsText(int $exposed, int $withheld): string
    {
        return $withheld > 0
            ? (string)__('%1 exposed, %2 withheld', $exposed, $withheld)
            : (string)$exposed;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function statusText(array $row): string
    {
        $parts = [];
        if ($row['read_only']) {
            $parts[] = (string)__('Read-only');
        }
        if ($row['output_public']) {
            $parts[] = (string)__('Public output');
        }
        if ($row['missing']) {
            $parts[] = (string)__('Missing: not exposed until a rescan finds it');
        }
        if ((string)($row['last_error'] ?? '') !== '') {
            $parts[] = (string)$row['last_error'];
        }

        return implode(' · ', $parts);
    }

    public function getSearchResult(): SearchResultInterface
    {
        $documents = [];
        foreach ($this->rows() as $item) {
            $document = $this->documentFactory->create();
            $document->setId($item['name']);
            $attributes = [];
            foreach ($item as $key => $value) {
                $attribute = $this->attributeValueFactory->create();
                $attribute->setAttributeCode($key);
                $attribute->setValue($value);
                $attributes[$key] = $attribute;
            }
            $document->setCustomAttributes($attributes);
            $documents[] = $document;
        }
        $searchResult = $this->searchResultFactory->create();
        $searchResult->setItems($documents);
        $searchResult->setTotalCount(count($documents));
        $searchResult->setSearchCriteria($this->getSearchCriteria());

        return $searchResult;
    }

    public function getData(): array
    {
        $items = $this->rows();

        return ['totalRecords' => count($items), 'items' => $items];
    }
}
