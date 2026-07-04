<?php

declare(strict_types=1);

/**
 * MySQL integration tests — requires docker-compose MySQL (see README).
 * Skips gracefully when the database is unavailable.
 */

require __DIR__ . '/../../superexport/src/autoload.php';
require __DIR__ . '/../Fixtures/WordPressSchema.php';
require __DIR__ . '/../Fixtures/OpenCartSchema.php';
require __DIR__ . '/../Fixtures/DrupalSchema.php';

use SuperExport\Adapters\Drupal\DrupalAdapter;
use SuperExport\Adapters\OpenCart\OpenCartAdapter;
use SuperExport\Adapters\WordPress\WordPressAdapter;
use SuperExport\Core\ExportPipeline;
use SuperExport\Core\ImportContext;
use SuperExport\Core\ImportPipeline;
use SuperExport\Core\IdRemapper;
use SuperExport\Storage\ManifestManager;
use SuperExport\Tests\Fixtures\DrupalSchema;
use SuperExport\Tests\Fixtures\OpenCartSchema;
use SuperExport\Tests\Fixtures\WordPressSchema;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

$host = getenv('SUPEREXPORT_DB_HOST') ?: '127.0.0.1';
$port = getenv('SUPEREXPORT_DB_PORT') ?: '3307';
$db = getenv('SUPEREXPORT_DB_NAME') ?: 'superexport_test';
$user = getenv('SUPEREXPORT_DB_USER') ?: 'superexport';
$pass = getenv('SUPEREXPORT_DB_PASS') ?: 'superexport';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (Throwable $e) {
    fwrite(STDERR, "SKIP: MySQL not available ({$e->getMessage()})\n");
    exit(0);
}

/** @var list<string> */
$failures = [];
$serializer = new Serializer();
$schema = new SchemaRegistry();
$manifest = new ManifestManager($serializer, $schema);
$batch = 50;

/** @param object $adapter */
function mysqlRoundTrip(
    PDO $pdo,
    string $label,
    object $adapter,
    string $root,
    string $statKey,
    string $countSql,
    int $minRows,
    ManifestManager $manifest,
    Serializer $serializer,
    SchemaRegistry $schema,
    int $batch,
    array &$failures,
): void {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_mysql_' . strtolower($label) . '_' . uniqid();
    $storage = $tmp . DIRECTORY_SEPARATOR . 'storage';
    mkdir($storage, 0775, true);

    try {
        if (!$adapter->detect($root)) {
            $failures[] = "{$label}: detect failed";

            return;
        }

        $export = new ExportPipeline($manifest, $serializer, $batch);
        $result = $export->run($adapter, $storage);
        if (($result['stats'][$statKey] ?? 0) < 1) {
            $failures[] = "{$label}: export produced no {$statKey}";

            return;
        }

        $import = new ImportPipeline($manifest, $schema, $serializer, $batch);
        $report = $import->run(
            $adapter,
            $storage,
            new ImportContext(new IdRemapper(), false, 'suffix'),
        );
        if (($report[$statKey]['created'] ?? 0) < 1) {
            $failures[] = "{$label}: import created no {$statKey}";

            return;
        }

        $count = (int) $pdo->query($countSql)->fetchColumn();
        if ($count < $minRows) {
            $failures[] = "{$label}: expected >= {$minRows} rows, got {$count}";
        }
    } finally {
        array_map('unlink', glob($storage . DIRECTORY_SEPARATOR . '*') ?: []);
        @rmdir($storage);
        @rmdir($tmp);
    }
}

$wpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_mysql_wp_root';
WordPressSchema::writeConfig($wpRoot);
mysqlRoundTrip(
    $pdo,
    'WordPress',
    new WordPressAdapter(['db' => ['pdo' => $pdo], 'batch_size' => $batch]),
    $wpRoot,
    'posts',
    'SELECT COUNT(*) FROM wp_posts WHERE post_type = \'post\'',
    2,
    $manifest,
    $serializer,
    $schema,
    $batch,
    $failures,
);

$ocRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_mysql_oc_root';
OpenCartSchema::writeConfig($ocRoot);
mysqlRoundTrip(
    $pdo,
    'OpenCart',
    new OpenCartAdapter(['db' => ['pdo' => $pdo], 'batch_size' => $batch]),
    $ocRoot,
    'products',
    'SELECT COUNT(*) FROM oc_product',
    2,
    $manifest,
    $serializer,
    $schema,
    $batch,
    $failures,
);

$drRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_mysql_dr_root';
DrupalSchema::writeConfig($drRoot);
mysqlRoundTrip(
    $pdo,
    'Drupal',
    new DrupalAdapter(['db' => ['pdo' => $pdo], 'batch_size' => $batch]),
    $drRoot,
    'posts',
    'SELECT COUNT(*) FROM node_field_data WHERE type = \'article\'',
    2,
    $manifest,
    $serializer,
    $schema,
    $batch,
    $failures,
);

if ($failures === []) {
    echo "MySQL integration tests passed.\n";
    exit(0);
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}
exit(1);
