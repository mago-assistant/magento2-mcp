<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Catalog;

/**
 * Decides whether an MCP tool reads or writes from its name alone, the type a Mago skill carries in
 * code; the catalog then lets the server's annotations turn a read into a write, never the reverse.
 *
 * MCP servers may annotate tools with readOnlyHint, but many do not, and a wrong "read" would
 * skip Mago's confirmation card. So a write word anywhere in the name wins. Otherwise the name is read
 * only when it starts or ends with a read verb ("get-product", "product-get") or ends with a read noun
 * ("route-info"). A read word anywhere else does not count, so a name like "log-rotate" fails closed to
 * write even though "rotate" is on no list: the write list never has to be complete to be safe, because an
 * unrecognised name is a write. The read-noun rule is the exception to that: "rotate-log" ends in the read
 * noun "log", so it classifies read even though "rotate" is an unknown verb. That one case is why the
 * README asks an administrator to review a new server's read tools rather than trust the classifier
 * alone.
 */
class ModeClassifier
{
    public const READ = 'read';
    public const WRITE = 'write';

    private const WRITE_WORDS = ['create', 'update', 'delete', 'set', 'add', 'assign', 'cancel', 'hold',
        'unhold', 'execute', 'run', 'runner', 'generate', 'reinitialize', 'flush', 'clean', 'write',
        'remove', 'import', 'sync', 'save', 'put', 'post', 'send', 'apply', 'upgrade', 'install',
        'clear', 'reindex', 'regenerate', 'rebuild', 'reset', 'purge', 'truncate', 'drop',
        'change', 'mark', 'replace', 'export', 'enable', 'disable', 'toggle', 'activate', 'deactivate',
        'unlock', 'merge', 'push', 'commit', 'refund', 'void', 'approve', 'publish', 'archive', 'trigger',
        'kill', 'restart', 'start', 'stop', 'move', 'edit', 'upload', 'fork', 'dismiss', 'charge', 'ship',
        'finalize', 'moderate', 'cleanup', 'fill', 'click', 'press', 'drag', 'close', 'fix', 'repair',
        'patch', 'capture', 'rename', 'erase', 'wipe', 'destroy', 'revoke', 'unassign', 'unlink', 'detach',
        'invalidate', 'expire', 'terminate', 'suspend', 'ban', 'reject', 'revert', 'rollback', 'restore',
        'prune'];

    /** Count as read at the start or the end of a name */
    private const READ_VERBS = ['get', 'list', 'inspect', 'search', 'show', 'read', 'find', 'help',
        'validate', 'diagnose', 'check'];

    /** Count as read only at the end of a name */
    private const READ_NOUNS = ['tree', 'info', 'status', 'context', 'schema', 'endpoints', 'comments',
        'items', 'orders', 'addresses', 'rewrites', 'log', 'structure', 'types', 'attributes', 'products',
        'configuration'];

    private const IRREVERSIBLE_WORDS = ['delete', 'cancel', 'creditmemo', 'remove', 'drop', 'truncate', 'purge'];

    /**
     * Segments that mean "run code, SQL or a command, or reshape the installation". Information only:
     * they add "[runs code, SQL or commands]" to the skill's description and "execution" to
     * mago:mcp:list, and never change a mode. "query"
     * is deliberately absent (it hid read tools like query-docs); "code", "deploy", "drop" and "truncate"
     * are absent because they matched coupon-code-list, deploy_mode_show and plain writes.
     */
    private const EXECUTION_WORDS = ['exec', 'execute', 'eval', 'evaluate', 'run', 'runner', 'script',
        'shell', 'bash', 'terminal', 'console', 'cli', 'command', 'php', 'sql', 'unsafe', 'generate',
        'scaffold', 'reinitialize', 'install', 'uninstall', 'upgrade', 'migrate', 'batch'];

    /** Segments that name records about a person. An informational flag for the admin, never a mode. */
    private const PERSONAL_DATA_WORDS = ['customer', 'customers', 'order', 'orders', 'invoice', 'invoices',
        'shipment', 'shipments', 'creditmemo', 'creditmemos', 'address', 'addresses', 'email', 'phone',
        'account', 'accounts', 'user', 'users', 'subscriber', 'subscribers', 'quote', 'quotes', 'cart',
        'wishlist', 'review', 'reviews'];

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

    /**
     * Whether a tool, by its name, deals in records about a person. Whole segments only: "preorder"
     * is not "order".
     */
    public function isPersonalData(string $toolName): bool
    {
        return array_intersect($this->segments($toolName), self::PERSONAL_DATA_WORDS) !== [];
    }

    public function isExecutionSurface(string $toolName): bool
    {
        return array_intersect($this->segments($toolName), self::EXECUTION_WORDS) !== [];
    }

    /**
     * @return string[]
     */
    private function segments(string $toolName): array
    {
        return array_values(array_filter(preg_split('/[-_]/', strtolower($toolName)) ?: []));
    }
}
