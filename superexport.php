<?php

declare(strict_types=1);

/**
 * SuperExport bootstrap: routes to CLI or Web entry point.
 * Web UI is implemented in a later phase; CLI works now.
 */

error_reporting(E_ALL);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');

require __DIR__ . '/superexport/src/autoload.php';

use SuperExport\Adapters\AdapterRegistrar;
use SuperExport\Cli\Commands;
use SuperExport\Core\Engine;
use SuperExport\Exceptions\SuperExportException;

/** @return array<string, mixed> */
function superexport_load_config(): array
{
    $path = __DIR__ . '/superexport/config.php';
    if (is_file($path)) {
        $config = require $path;
        if (is_array($config)) {
            return $config;
        }
    }

    return [];
}

$config = superexport_load_config();
$config['cms_root'] ??= __DIR__;

if (PHP_SAPI === 'cli') {
    $engine = new Engine($config, static function (string $message): void {
        fwrite(STDOUT, $message . PHP_EOL);
    });

    AdapterRegistrar::registerAll($engine, $config);

    exit((new Commands($engine))->run($argv));
}

// --- Web entry point (full UI arrives in phase 5) ---

$engine = new Engine($config);
AdapterRegistrar::registerAll($engine, $config);

try {
    $secret = $engine->getSecretKey();
} catch (SuperExportException) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'SuperExport is not configured: create superexport/config.php with a secret_key.';
    exit;
}

$providedKey = (string) ($_GET['key'] ?? '');
if ($providedKey === '' || !hash_equals($secret, $providedKey)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden.';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "SuperExport core is installed.\n";
echo "Web UI is not available yet; use the CLI:\n";
echo "  php superexport.php detect | export | import\n";
