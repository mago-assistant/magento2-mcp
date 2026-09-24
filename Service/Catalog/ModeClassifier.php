<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

/**
 * Decides whether an MCP tool reads or writes from its name alone.
 *
 * MCP servers may annotate tools with readOnlyHint, but Bricklayer does not, and a wrong "read" would
 * skip Mago's confirmation card. So a write word anywhere in the name wins. Otherwise the name is read
 * only when it starts or ends with a read verb ("get-product", "product-get") or ends with a read noun
 * ("route-info"). A read word anywhere else does not count, so a name like "log-wipe" fails closed to
 * write even though "wipe" is on no list: the lists never have to be complete to be safe.
 */
class ModeClassifier
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const DISABLED = 'disabled';

    private const WRITE_WORDS = ['create', 'update', 'delete', 'set', 'add', 'assign', 'cancel', 'hold',
        'unhold', 'execute', 'run', 'runner', 'generate', 'reinitialize', 'flush', 'clean', 'write',
        'remove', 'import', 'sync', 'save', 'put', 'post', 'send', 'apply', 'upgrade', 'install',
        'clear', 'reindex', 'regenerate', 'rebuild', 'reset', 'purge', 'truncate', 'drop'];

    /** Count as read at the start or the end of a name */
    private const READ_VERBS = ['get', 'list', 'inspect', 'search', 'show', 'read', 'find', 'help',
        'validate', 'diagnose', 'check'];

    /** Count as read only at the end of a name */
    private const READ_NOUNS = ['tree', 'info', 'status', 'context', 'schema', 'endpoints', 'comments',
        'items', 'orders', 'addresses', 'rewrites', 'log', 'structure', 'types', 'attributes', 'products',
        'configuration'];

    private const IRREVERSIBLE_WORDS = ['delete', 'cancel', 'creditmemo', 'remove'];

    public function classify(string $toolName): string
    {
        $segments = $this->segments($toolName);
        if (array_intersect($segments, self::WRITE_WORDS) !== []) {
            return self::WRITE;
        }
        if ($segments === []) {
            return self::WRITE;
        }
        $first = $segments[0];
        $last = $segments[count($segments) - 1];
        if (in_array($first, self::READ_VERBS, true)
            || in_array($last, self::READ_VERBS, true)
            || in_array($last, self::READ_NOUNS, true)
        ) {
            return self::READ;
        }

        return self::WRITE;
    }

    public function isIrreversible(string $toolName): bool
    {
        return array_intersect($this->segments($toolName), self::IRREVERSIBLE_WORDS) !== [];
    }

    public function isValidMode(string $mode): bool
    {
        return in_array($mode, [self::READ, self::WRITE, self::DISABLED], true);
    }

    /**
     * @return string[]
     */
    private function segments(string $toolName): array
    {
        return array_values(array_filter(preg_split('/[-_]/', strtolower($toolName)) ?: []));
    }
}
