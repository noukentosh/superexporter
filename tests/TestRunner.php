<?php

declare(strict_types=1);

namespace SuperExport\Tests;

use PDO;
use SuperExport\Adapters\Bitrix\BitrixAdapter;
use SuperExport\Adapters\WordPress\WordPressAdapter;
use SuperExport\Core\ExportPipeline;
use SuperExport\Core\ImportContext;
use SuperExport\Core\ImportPipeline;
use SuperExport\Core\IdRemapper;
use SuperExport\Storage\ManifestManager;
use SuperExport\Tests\Fixtures\BitrixSchema;
use SuperExport\Tests\Fixtures\WordPressSchema;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

final class TestRunner
{
    /** @var list<string> */
    private array $failures = [];

    public function runAll(): int
    {
        $this->testWordPressRoundTrip();
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
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_wp_' . uniqid();
        $storage = $tmp . DIRECTORY_SEPARATOR . 'storage';
        $root = $tmp . DIRECTORY_SEPARATOR . 'wp';
        WordPressSchema::writeConfig($root);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        WordPressSchema::create($pdo);

        $config = ['db' => ['pdo' => $pdo], 'batch_size' => 50];
        $adapter = new WordPressAdapter($config);
        if (!$adapter->detect($root)) {
            $this->fail('WordPress detect failed');

            return;
        }

        $serializer = new Serializer();
        $schema = new SchemaRegistry();
        $manifest = new ManifestManager($serializer, $schema);

        $export = new ExportPipeline($manifest, $serializer, 50);
        $exportResult = $export->run($adapter, $storage);

        if (($exportResult['stats']['posts'] ?? 0) < 1) {
            $this->fail('WordPress export produced no posts');

            return;
        }

        $target = new WordPressAdapter($config);
        $target->detect($root);

        $import = new ImportPipeline($manifest, $schema, $serializer, 50);
        $report = $import->run(
            $target,
            $storage,
            new ImportContext(new IdRemapper(), false, 'suffix'),
        );

        if (($report['posts']['created'] ?? 0) < 1) {
            $this->fail('WordPress round-trip import created no posts');

            return;
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM wp_posts WHERE post_type = \'post\'')->fetchColumn();
        if ($count < 2) {
            $this->fail('WordPress round-trip expected at least 2 posts, got ' . $count);
        }

        $this->cleanup($tmp);
    }

    private function testBitrixToWordPress(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_bxwp_' . uniqid();
        $storage = $tmp . DIRECTORY_SEPARATOR . 'storage';
        $bxRoot = $tmp . DIRECTORY_SEPARATOR . 'bitrix_site';
        $wpRoot = $tmp . DIRECTORY_SEPARATOR . 'wp_site';

        BitrixSchema::writeConfig($bxRoot);
        WordPressSchema::writeConfig($wpRoot);

        $bxPdo = new PDO('sqlite::memory:');
        $bxPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        BitrixSchema::create($bxPdo);

        $wpPdo = new PDO('sqlite::memory:');
        $wpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        WordPressSchema::create($wpPdo);

        $bxConfig = ['db' => ['pdo' => $bxPdo], 'batch_size' => 50];
        $wpConfig = ['db' => ['pdo' => $wpPdo], 'batch_size' => 50];

        $bxAdapter = new BitrixAdapter($bxConfig);
        if (!$bxAdapter->detect($bxRoot)) {
            $this->fail('Bitrix detect failed');

            return;
        }

        $serializer = new Serializer();
        $schema = new SchemaRegistry();
        $manifest = new ManifestManager($serializer, $schema);

        $export = new ExportPipeline($manifest, $serializer, 50);
        $exportResult = $export->run($bxAdapter, $storage);

        if (($exportResult['stats']['posts'] ?? 0) < 1) {
            $this->fail('Bitrix export produced no posts');

            return;
        }

        $wpAdapter = new WordPressAdapter($wpConfig);
        if (!$wpAdapter->detect($wpRoot)) {
            $this->fail('WordPress detect failed for cross-import');

            return;
        }

        $import = new ImportPipeline($manifest, $schema, $serializer, 50);
        $report = $import->run(
            $wpAdapter,
            $storage,
            new ImportContext(new IdRemapper(), false, 'suffix'),
        );

        if (($report['posts']['created'] ?? 0) < 1) {
            $this->fail('Bitrix→WP cross-import created no posts');

            return;
        }

        $stmt = $wpPdo->query('SELECT post_title FROM wp_posts WHERE post_type = \'post\' ORDER BY ID');
        $titles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('Bitrix Post', $titles, true)) {
            $this->fail('Bitrix→WP: expected imported title "Bitrix Post"');
        }

        $this->cleanup($tmp);
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
