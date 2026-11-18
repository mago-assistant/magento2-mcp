<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

/*
 * The module is autoloaded either through its own vendor/ (standalone checkout, as in CI) or
 * through the vendor/ of the Magento install it lives in (app/code or a path repository).
 */
$autoloaders = array_filter(
    [
        __DIR__ . '/../vendor/autoload.php',            // standalone checkout
        __DIR__ . '/../../../../vendor/autoload.php',   // installed under vendor/mago-assistant/magento2-mcp
        __DIR__ . '/../../../../../vendor/autoload.php', // installed under app/code/MagoAssistant/Mcp
    ],
    'is_file'
);

if ($autoloaders === []) {
    throw new \RuntimeException(
        'No composer autoloader found. Run `composer update` in the module, or run the suite from a Magento install.'
    );
}

require reset($autoloaders);
