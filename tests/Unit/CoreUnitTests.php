<?php

declare(strict_types=1);

namespace SuperExport\Tests\Unit;

use SuperExport\Core\IdRemapper;
use SuperExport\Exceptions\IncompatibleFormatException;
use SuperExport\Storage\ManifestManager;
use SuperExport\Universal\EntityType;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

final class CoreUnitTests
{
    /** @var list<string> */
    private array $failures = [];

    /** @return list<string> */
    public function runAll(): array
    {
        $this->testSchemaRegistryDescribe();
        $this->testSchemaRegistryValidate();
        $this->testIdRemapper();
        $this->testManifestManagerBuildLoad();

        return $this->failures;
    }

    private function testSchemaRegistryDescribe(): void
    {
        $registry = new SchemaRegistry();
        $fields = $registry->describe([EntityType::Post, EntityType::Category]);

        if (!isset($fields['posts']['source_id'], $fields['categories']['name'])) {
            $this->fail('SchemaRegistry::describe missing expected fields');
        }
    }

    private function testSchemaRegistryValidate(): void
    {
        $registry = new SchemaRegistry();
        $valid = [
            'source_id' => 1,
            'slug' => 'hello',
            'title' => 'Hello',
            'status' => 'published',
        ];
        if ($registry->validate(EntityType::Post, $valid) !== []) {
            $this->fail('SchemaRegistry should accept valid post record');
        }

        $errors = $registry->validate(EntityType::Post, ['slug' => 'x']);
        if ($errors === [] || !str_contains($errors[0], 'source_id')) {
            $this->fail('SchemaRegistry should reject record missing source_id');
        }
    }

    private function testIdRemapper(): void
    {
        $remapper = new IdRemapper();
        $remapper->remember(EntityType::Post, 10, 100);
        $remapper->rememberBatch(EntityType::Category, ['1' => 501, '2' => 502]);

        if ($remapper->resolve(EntityType::Post, 10) !== 100) {
            $this->fail('IdRemapper::resolve failed');
        }
        if (!$remapper->has(EntityType::Category, 2)) {
            $this->fail('IdRemapper::has failed');
        }

        $restored = IdRemapper::fromArray($remapper->toArray());
        if ($restored->resolve(EntityType::Category, 1) !== 501) {
            $this->fail('IdRemapper round-trip via toArray/fromArray failed');
        }

        $batch = new IdRemapper();
        $batch->rememberBatchFromMap(['posts' => ['5' => 55]]);
        if ($batch->resolve(EntityType::Post, 5) !== 55) {
            $this->fail('IdRemapper::rememberBatchFromMap failed');
        }
    }

    private function testManifestManagerBuildLoad(): void
    {
        $serializer = new Serializer();
        $manager = new ManifestManager($serializer, new SchemaRegistry());
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_manifest_' . uniqid();
        mkdir($tmp, 0775, true);

        try {
            $manifest = $manager->build(
                ['cms' => 'wordpress', 'cms_version' => '6.0', 'site_url' => 'https://test.local', 'db_prefix' => 'wp_'],
                [EntityType::Post],
                ['posts' => 3],
                ['wp.post_title' => 'title'],
                ['posts' => ['posts_0001.json']],
            );
            $manager->save($tmp, $manifest);
            $loaded = $manager->load($tmp);

            if (($loaded['format_version'] ?? '') !== ManifestManager::FORMAT_VERSION) {
                $this->fail('ManifestManager load returned wrong format_version');
            }

            $entities = $manager->getEntities($loaded);
            if ($entities !== [EntityType::Post]) {
                $this->fail('ManifestManager::getEntities mismatch');
            }

            if ($manager->getChunks($loaded, EntityType::Post) !== ['posts_0001.json']) {
                $this->fail('ManifestManager::getChunks mismatch');
            }

            try {
                $bad = ['format_version' => '99.0.0'];
                file_put_contents($tmp . DIRECTORY_SEPARATOR . 'manifest_bad.json', $serializer->encode($bad));
                $badManifest = $serializer->decode((string) file_get_contents($tmp . DIRECTORY_SEPARATOR . 'manifest_bad.json'));
                // assertCompatible is private; load() validates on read
                file_put_contents($tmp . DIRECTORY_SEPARATOR . 'manifest.json', $serializer->encode($badManifest));
                $manager->load($tmp);
                $this->fail('ManifestManager should reject incompatible format_version');
            } catch (IncompatibleFormatException) {
                // expected
            }
        } finally {
            @unlink($tmp . DIRECTORY_SEPARATOR . 'manifest.json');
            @rmdir($tmp);
        }
    }

    private function fail(string $message): void
    {
        $this->failures[] = $message;
    }
}
