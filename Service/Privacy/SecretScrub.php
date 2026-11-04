<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Privacy;

/**
 * Credentials an MCP tool may print, masked before the text goes anywhere: to the model (and so to the
 * AI provider), to the admin's chat, to a log. A read tool is cardless, and a server's diagnostics
 * (database connection info, a decrypted configuration value, a log line with an authorization header)
 * are reads by name; Mago's own scrub knows emails and phone numbers, not passwords. This is a last
 * line, pattern-based, and masks more than it needs to rather than less: a value after a key that
 * looks like a secret, an encrypted Magento blob, a bearer header, a URL password, an admin URL key.
 *
 * A module extends the patterns from di.xml (a default can never be removed):
 *
 *     <type name="MagoAssistant\Mcp\Service\Privacy\SecretScrub">
 *         <arguments>
 *             <argument name="additionalPatterns" xsi:type="array">
 *                 <item name="vendor_license" xsi:type="string">/(license[_-]?code\s*[:=]\s*)\S+/i</item>
 *             </argument>
 *         </arguments>
 *     </type>
 *
 * An additional pattern's first capturing group, when it has one, is kept in front of the mask.
 */
class SecretScrub
{
    public const MASK = '[secret]';

    private const KEY_WORDS = 'password|passwd|pwd|passphrase|secret|api[_-]?key|private[_-]?key|access[_-]?key'
        . '|client[_-]?secret|auth[_-]?token|access[_-]?token|refresh[_-]?token|bearer[_-]?token|token|dsn'
        . '|connection[-_ ]?string|crypt[_-]?key|encryption[_-]?key|signing[_-]?key';

    /** @var array<int,array{0:string,1:string}> pattern and replacement */
    private const DEFAULT_PATTERNS = [
        // key: value, key = value, "key": "value", path/key | value (a CLI table)
        ['/\b(' . self::KEY_WORDS . ')(["\'`]?\s*(?:[:=]|\|)\s*["\'`]?)(?!\s)([^\s"\'`,|}\]]+)/i', '$1$2' . self::MASK],
        // a Magento encrypted value: <key number>:<cipher>:<base64>
        ['/\b\d+:\d+:[A-Za-z0-9+\/=]{16,}/', self::MASK],
        // Authorization: Bearer <token>, Basic <credentials>
        ['/(authorization\s*[:=]\s*(?:bearer|basic)\s+)\S+/i', '$1' . self::MASK],
        // a password inside a URL: scheme://user:password@host
        ['/(:\/\/[^\/\s:@]+:)[^@\s\/]+@/', '$1' . self::MASK . '@'],
        // mysql -p'password' / -p"password" in a quoted CLI string
        ['/(\s-p)(["\'])[^"\']*\2/', '$1$2' . self::MASK . '$2'],
        // an admin URL carrying its secret key
        ['/(\/key\/)[0-9a-f]{32}(\/)/i', '$1' . self::MASK . '$2'],
    ];

    /** @var array<int,array{0:string,1:string}> */
    private readonly array $patterns;

    /**
     * @param array<string,string> $additionalPatterns regular expressions from di.xml; see the class comment
     */
    public function __construct(array $additionalPatterns = [])
    {
        $patterns = self::DEFAULT_PATTERNS;
        foreach ($additionalPatterns as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = [$pattern, '$1' . self::MASK];
            }
        }
        $this->patterns = $patterns;
    }

    public function mask(string $text): string
    {
        foreach ($this->patterns as [$pattern, $replacement]) {
            $masked = preg_replace($pattern, $replacement, $text);
            if (is_string($masked)) {
                $text = $masked;
            }
        }

        return $text;
    }

    /**
     * Every string leaf of a decoded result, masked in place; keys are kept so the shape survives.
     */
    public function maskLeaves(mixed $data): mixed
    {
        if (is_string($data)) {
            return $this->mask($data);
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->maskLeaves($value);
            }
        }

        return $data;
    }

    public function containsSecret(string $text): bool
    {
        return $this->mask($text) !== $text;
    }
}
