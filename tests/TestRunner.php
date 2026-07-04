<?php

declare(strict_types=1);

namespace SuperExport\Tests;

use PDO;
use SuperExport\Adapters\Bitrix\BitrixAdapter;
use SuperExport\Adapters\Drupal\DrupalAdapter;
use SuperExport\Adapters\Joomla\JoomlaAdapter;
use SuperExport\Adapters\Modx\ModxAdapter;
use SuperExport\Adapters\OpenCart\OpenCartAdapter;
use SuperExport\Adapters\WordPress\WordPressAdapter;
use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Core\ExportPipeline;
use SuperExport\Core\ImportContext;
use SuperExport\Core\ImportPipeline;
use SuperExport\Core\IdRemapper;
use SuperExport\Storage\ManifestManager;
use SuperExport\Tests\Fixtures\BitrixSchema;
use SuperExport\Tests\Fixtures\DrupalSchema;
use SuperExport\Tests\Fixtures\JoomlaSchema;
use SuperExport\Tests\Fixtures\ModxSchema;
use SuperExport\Tests\Fixtures\OpenCartSchema;
use SuperExport\Tests\Fixtures\WordPressSchema;
use SuperExport\Tests\Unit\CoreUnitTests;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

final class TestRunner
{
    /** @var list<string> */
    private array $failures = [];

    public function runAll(): int
    {
        foreach ((new CoreUnitTests())->runAll() as $failure) {
            $this->failures[] = $failure;
        }

        $this->testWordPressRoundTrip();
        $this->testBitrixRoundTrip();
        $this->testBitrixDetectViaConnectFromCms();
        $this->testJoomlaRoundTrip();
        $this->testModxRoundTrip();
        $this->testOpenCartExport();
        $this->testDrupalExport();
        $this->testBitrixToWordPress();

        if ($this->failures === []) {
            echo "All tests passed.\n";

            return 0;
        }

        foreach ($this->failures as $failure) {
            fwrite(STDERR, "FAIL: {$failure}\n");
        }

        return 1;
    }

    private function testWordPressRoundTrip(): void
    {
        $this->roundTripSameCms(
            'WordPress',
            WordPressAdapter::class,
            'posts',
            'SELECT COUNT(*) FROM wp_posts WHERE post_type = \'post\'',
            2,
            static fn (PDO $pdo) => WordPressSchema::create($pdo),
            static fn (string $root) => WordPressSchema::writeConfig($root),
        );
    }

    private function testBitrixRoundTrip(): void
    {
        $this->roundTripSameCms(
            'Bitrix',
            BitrixAdapter::class,
            'posts',
            'SELECT COUNT(*) FROM b_iblock_element',
            2,
            static fn (PDO $pdo) => BitrixSchema::create($pdo),
            static fn (string $root) => BitrixSchema::writeConfig($root),
        );
    }

    private function testJoomlaRoundTrip(): void
    {
        $this->roundTripSameCms(
            'Joomla',
            JoomlaAdapter::class,
            'posts',
            'SELECT COUNT(*) FROM jos_content',
            2,
            static fn (PDO $pdo) => JoomlaSchema::create($pdo),
            static fn (string $root) => JoomlaSchema::writeConfig($root),
            'Joomla Post',
        );
    }

    private function testModxRoundTrip(): void
    {
        $this->roundTripSameCms(
            'MODX',
            ModxAdapter::class,
            'posts',
            'SELECT COUNT(*) FROM modx_site_content WHERE isfolder = 0',
            2,
            static fn (PDO $pdo) => ModxSchema::create($pdo),
            static fn (string $root) => ModxSchema::writeConfig($root),
            'MODX Post',
        );
    }

    private function testOpenCartExport(): void
    {
        $this->exportSmoke(
            'OpenCart',
            OpenCartAdapter::class,
            'products',
            static fn (PDO $pdo) => OpenCartSchema::create($pdo),
            static fn (string $root) => OpenCartSchema::writeConfig($root),
        );
    }

    private function testDrupalExport(): void
    {
        $this->exportSmoke(
            'Drupal',
            DrupalAdapter::class,
            'posts',
            static fn (PDO $pdo) => DrupalSchema::create($pdo),
            static fn (string $root) => DrupalSchema::writeConfig($root),
        );
    }

    private function testBitrixDetectViaConnectFromCms(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_bitrix_conn_' . uniqid();
        $root = $tmp . DIRECTORY_SEPARATOR . 'site';

        try {
            BitrixSchema::writeConfig($root);
            $pdo = $this->sqlite();
            BitrixSchema::create($pdo);

            $adapter = new BitrixAdapter(['db' => ['pdo' => $pdo]]);
            $ref = new \ReflectionClass($adapter);

            $readMeta = $ref->getMethod('readCmsMetadata');
            $readMeta->setAccessible(true);
            $readMeta->invoke($adapter, $root);

            if ($adapter->getDbPrefix() !== 'b_') {
                $this->fail('Bitrix readCmsMetadata: expected db prefix b_');
            }

            $tableExists = $ref->getMethod('tableExists');
            $tableExists->setAccessible(true);
            if (!$tableExists->invoke($adapter, 'iblock_element')) {
                $this->fail('Bitrix tableExists(iblock_element) failed (table prefix regression)');
            }

            $bare = new BitrixAdapter();
            $refBare = new \ReflectionClass($bare);
            $dbPrefix = $refBare->getProperty('dbPrefix');
            $dbPrefix->setAccessible(true);
            $dbPrefix->setValue($bare, 'b_');

            $connect = $refBare->getMethod('connectFromCms');
            $connect->setAccessible(true);
            try {
                $connect->invoke($bare, $root);
            } catch (\Throwable) {
                // No MySQL in unit tests; connect may fail after prefix check.
            }

            if ($dbPrefix->getValue($bare) !== 'b_') {
                $this->fail('Bitrix connectFromCms cleared db prefix b_');
            }
        } finally {
            $this->cleanup($tmp);
        }
    }

    private function testBitrixToWordPress(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_bxwp_' . uniqid();
        $storage = $tmp . DIRECTORY_SEPARATOR . 'storage';
        $bxRoot = $tmp . DIRECTORY_SEPARATOR . 'bitrix_site';
        $wpRoot = $tmp . DIRECTORY_SEPARATOR . 'wp_site';

        try {
            BitrixSchema::writeConfig($bxRoot);
            WordPressSchema::writeConfig($wpRoot);

            $bxPdo = $this->sqlite();
            BitrixSchema::create($bxPdo);

            $wpPdo = $this->sqlite();
            WordPressSchema::create($wpPdo);

            $bxAdapter = new BitrixAdapter(['db' => ['pdo' => $bxPdo], 'batch_size' => 50]);
            if (!$bxAdapter->detect($bxRoot)) {
                $this->fail('Bitrix detect failed');

                return;
            }

            $manifest = $this->manifest();
            $export = new ExportPipeline($manifest, new Serializer(), 50);
            $exportResult = $export->run($bxAdapter, $storage);

            if (($exportResult['stats']['posts'] ?? 0) < 1) {
                $this->fail('Bitrix export produced no posts');

                return;
            }

            $wpAdapter = new WordPressAdapter(['db' => ['pdo' => $wpPdo], 'batch_size' => 50]);
            if (!$wpAdapter->detect($wpRoot)) {
                $this->fail('WordPress detect failed for cross-import');

                return;
            }

            $import = new ImportPipeline($manifest, new SchemaRegistry(), new Serializer(), 50);
            $report = $import->run(
                $wpAdapter,
                $storage,
                new ImportContext(new IdRemapper(), false, 'suffix'),
            );

            if (($report['posts']['created'] ?? 0) < 1) {
                $this->fail('Bitrix→WP cross-import created no posts');

                return;
            }

            $titles = $wpPdo->query('SELECT post_title FROM wp_posts WHERE post_type = \'post\' ORDER BY ID')
                ->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('Bitrix Post', $titles, true)) {
                $this->fail('Bitrix→WP: expected imported title "Bitrix Post"');
            }
        } finally {
            $this->cleanup($tmp);
        }
    }

    /**
     * @param class-string $adapterClass
     * @param callable(PDO): void $seed
     * @param callable(string): void $writeConfig
     */
    private function roundTripSameCms(
        string $label,
        string $adapterClass,
        string $statKey,
        string $countSql,
        int $minRows,
        callable $seed,
        callable $writeConfig,
        ?string $expectedTitle = null,
    ): void {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_' . strtolower($label) . '_' . uniqid();
        $storage = $tmp . DIRECTORY_SEPARATOR . 'storage';
        $root = $tmp . DIRECTORY_SEPARATOR . 'site';

        try {
            $writeConfig($root);
            $pdo = $this->sqlite();
            $seed($pdo);

            /** @var CmsAdapterInterface $adapter */
            $adapter = new $adapterClass(['db' => ['pdo' => $pdo], 'batch_size' => 50]);
            if (!$adapter->detect($root)) {
                $this->fail("{$label} detect failed");

                return;
            }

            $manifest = $this->manifest();
            $export = new ExportPipeline($manifest, new Serializer(), 50);
            $exportResult = $export->run($adapter, $storage);

            if (($exportResult['stats'][$statKey] ?? 0) < 1) {
                $this->fail("{$label} export produced no {$statKey}");

                return;
            }

            /** @var CmsAdapterInterface $target */
            $target = new $adapterClass(['db' => ['pdo' => $pdo], 'batch_size' => 50]);
            $target->detect($root);

            $import = new ImportPipeline($manifest, new SchemaRegistry(), new Serializer(), 50);
            $report = $import->run(
                $target,
                $storage,
                new ImportContext(new IdRemapper(), false, 'suffix'),
            );

            if (($report[$statKey]['created'] ?? 0) < 1) {
                $this->fail("{$label} round-trip import created no {$statKey}");

                return;
            }

            $count = (int) $pdo->query($countSql)->fetchColumn();
            if ($count < $minRows) {
                $this->fail("{$label} round-trip expected at least {$minRows} rows, got {$count}");
            }

            if ($expectedTitle !== null) {
                $found = false;
                foreach ($this->findTitles($pdo, $label) as $title) {
                    if ($title === $expectedTitle) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $this->fail("{$label} round-trip: expected title \"{$expectedTitle}\"");
                }
            }
        } finally {
            $this->cleanup($tmp);
        }
    }

    /**
     * @param class-string $adapterClass
     * @param callable(PDO): void $seed
     * @param callable(string): void $writeConfig
     */
    private function exportSmoke(
        string $label,
        string $adapterClass,
        string $statKey,
        callable $seed,
        callable $writeConfig,
    ): void {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_export_' . strtolower($label) . '_' . uniqid();
        $storage = $tmp . DIRECTORY_SEPARATOR . 'storage';
        $root = $tmp . DIRECTORY_SEPARATOR . 'site';

        try {
            $writeConfig($root);
            $pdo = $this->sqlite();
            $seed($pdo);

            /** @var CmsAdapterInterface $adapter */
            $adapter = new $adapterClass(['db' => ['pdo' => $pdo], 'batch_size' => 50]);
            if (!$adapter->detect($root)) {
                $this->fail("{$label} detect failed (export smoke)");

                return;
            }

            $manifest = $this->manifest();
            $export = new ExportPipeline($manifest, new Serializer(), 50);
            $result = $export->run($adapter, $storage);

            if (($result['stats'][$statKey] ?? 0) < 1) {
                $this->fail("{$label} export smoke: no {$statKey} exported");
            }
        } finally {
            $this->cleanup($tmp);
        }
    }

    /** @return list<string> */
    private function findTitles(PDO $pdo, string $label): array
    {
        return match ($label) {
            'Joomla' => $pdo->query('SELECT title FROM jos_content')->fetchAll(PDO::FETCH_COLUMN),
            'MODX' => $pdo->query('SELECT pagetitle FROM modx_site_content')->fetchAll(PDO::FETCH_COLUMN),
            default => [],
        };
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function manifest(): ManifestManager
    {
        return new ManifestManager(new Serializer(), new SchemaRegistry());
    }

    private function fail(string $message): void
    {
        $this->failures[] = $message;
    }

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
