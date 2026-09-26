<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mcp\Service\Discovery;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Servers shipped by Composer packages: an explicit extra.mago-mcp declaration, or failing that a
 * vendor/bin binary with "mcp" in its name.
 */
class ComposerSource implements SourceInterface
{
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly File $file
    ) {
    }

    public function discover(): array
    {
        $root = rtrim($this->directoryList->getRoot(), '/');
        $path = $root . '/vendor/composer/installed.json';
        try {
            if (!$this->file->isReadable($path)) {
                return [];
            }
            $decoded = json_decode($this->file->fileGetContents($path), true);
        } catch (FileSystemException) {
            return [];
        }
        $packages = is_array($decoded['packages'] ?? null) ? $decoded['packages'] : (is_array($decoded) ? $decoded : []);
        $servers = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                continue;
            }
            $server = $this->fromExtra($package) ?? $this->fromBin($package, $root);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * @param array<string,mixed> $package
     */
    private function fromExtra(array $package): ?DiscoveredServer
    {
        $extra = $package['extra']['mago-mcp'] ?? null;
        if (!is_array($extra) || !is_array($extra['command'] ?? null) || $extra['command'] === []) {
            return null;
        }
        $name = DiscoveredServer::normaliseName((string)($extra['name'] ?? $this->defaultName($package['name'])));

        return new DiscoveredServer(
            $name,
            array_values(array_map('strval', $extra['command'])),
            array_map('strval', is_array($extra['env'] ?? null) ? $extra['env'] : []),
            isset($extra['cwd']) ? (string)$extra['cwd'] : null,
            DiscoveredServer::SOURCE_COMPOSER
        );
    }

    /**
     * @param array<string,mixed> $package
     */
    private function fromBin(array $package, string $root): ?DiscoveredServer
    {
        foreach (is_array($package['bin'] ?? null) ? $package['bin'] : [] as $bin) {
            $basename = basename((string)$bin);
            if (!preg_match('/mcp/i', $basename) || str_ends_with($basename, '-docker')) {
                continue;
            }
            try {
                if (!$this->file->isFile($root . '/vendor/bin/' . $basename)) {
                    continue;
                }
            } catch (FileSystemException) {
                continue;
            }

            return new DiscoveredServer(
                DiscoveredServer::normaliseName($this->defaultName($package['name'])),
                ['php', 'vendor/bin/' . $basename],
                [],
                null,
                DiscoveredServer::SOURCE_COMPOSER
            );
        }

        return null;
    }

    /**
     * The package name after the vendor; DiscoveredServer::normaliseName strips the rest.
     */
    private function defaultName(string $packageName): string
    {
        return substr($packageName, (int)strrpos($packageName, '/') + 1);
    }
}
