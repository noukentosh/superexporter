<?php

declare(strict_types=1);

namespace SuperExport\Tests\Unit;

use SuperExport\Core\EntityMapper;
use SuperExport\Core\IdRemapper;
use SuperExport\Exceptions\IncompatibleFormatException;
use SuperExport\Storage\ManifestManager;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
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
        $this->testEntityKeyParse();
        $this->testSchemaRegistryDescribe();
        $this->testSchemaRegistryValidate();
        $this->testIdRemapper();
        $this->testManifestManagerBuildLoad();
        $this->testManifestBackwardCompat();
        $this->testEntityMapper();

        return $this->failures;
    }

    private function testEntityKeyParse(): void
    {
        if (!EntityKey::fromStandard(EntityType::Post)->equals(EntityKey::parse('posts'))) {
            $this->fail('EntityKey::parse standard posts failed');
        }
        if (EntityKey::cpt('portfolio')->value !== 'cpt:portfolio') {
            $this->fail('EntityKey::cpt failed');
        }
        if (EntityKey::iblock(12)->value !== 'iblock:12') {
            $this->fail('EntityKey::iblock failed');
        }
        if (EntityKey::tryParse('invalid key') !== null) {
            $this->fail('EntityKey::tryParse should reject invalid key');
        }
    }

    private function testSchemaRegistryDescribe(): void
    {
        $registry = new SchemaRegistry();
        $fields = $registry->describe([
            EntityKey::fromStandard(EntityType::Post),
            EntityKey::fromStandard(EntityType::Category),
            EntityKey::cpt('portfolio'),
        ]);

        if (!isset($fields['posts']['source_id'], $fields['categories']['name'], $fields['cpt:portfolio']['title'])) {
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
        if ($registry->validate(EntityKey::fromStandard(EntityType::Post), $valid) !== []) {
            $this->fail('SchemaRegistry should accept valid post record');
        }

        $errors = $registry->validate(EntityKey::fromStandard(EntityType::Post), ['slug' => 'x']);
        if ($errors === [] || !str_contains($errors[0], 'source_id')) {
            $this->fail('SchemaRegistry should reject record missing source_id');
        }
    }

    private function testIdRemapper(): void
    {
        $remapper = new IdRemapper();
        $postKey = EntityKey::fromStandard(EntityType::Post);
        $catKey = EntityKey::fromStandard(EntityType::Category);
        $remapper->remember($postKey, 10, 100);
        $remapper->rememberBatch($catKey, ['1' => 501, '2' => 502]);

        if ($remapper->resolve($postKey, 10) !== 100) {
            $this->fail('IdRemapper::resolve failed');
        }
        if (!$remapper->has($catKey, 2)) {
            $this->fail('IdRemapper::has failed');
        }

        $restored = IdRemapper::fromArray($remapper->toArray());
        if ($restored->resolve($catKey, 1) !== 501) {
            $this->fail('IdRemapper round-trip via toArray/fromArray failed');
        }

        $batch = new IdRemapper();
        $batch->rememberBatchFromMap(['posts' => ['5' => 55], 'cpt:portfolio' => ['1' => 10]]);
        if ($batch->resolve($postKey, 5) !== 55) {
            $this->fail('IdRemapper::rememberBatchFromMap failed for posts');
        }
        if ($batch->resolve(EntityKey::cpt('portfolio'), 1) !== 10) {
            $this->fail('IdRemapper::rememberBatchFromMap failed for cpt');
        }
    }

    private function testManifestManagerBuildLoad(): void
    {
        $serializer = new Serializer();
        $manager = new ManifestManager($serializer, new SchemaRegistry());
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_manifest_' . uniqid();
        mkdir($tmp, 0775, true);

        try {
            $postKey = EntityKey::fromStandard(EntityType::Post);
            $cptKey = EntityKey::cpt('portfolio');
            $definitions = [
                $postKey->value => EntityDefinition::forStandard(EntityType::Post),
                $cptKey->value => new EntityDefinition(
                    key: $cptKey,
                    kind: EntityDefinition::KIND_CONTENT,
                    label: 'Portfolio',
                    canonicalKind: EntityDefinition::CANONICAL_POST,
                    source: ['cms' => 'wordpress', 'native_type' => 'portfolio'],
                ),
            ];

            $manifest = $manager->build(
                ['cms' => 'wordpress', 'cms_version' => '6.0', 'site_url' => 'https://test.local', 'db_prefix' => 'wp_'],
                [$postKey, $cptKey],
                ['posts' => 3, 'cpt:portfolio' => 5],
                ['wp.post_title' => 'title'],
                ['posts' => ['posts_0001.json'], 'cpt:portfolio' => ['cpt:portfolio_0001.json']],
                $definitions,
            );
            $manager->save($tmp, $manifest);
            $loaded = $manager->load($tmp);

            if (($loaded['format_version'] ?? '') !== ManifestManager::FORMAT_VERSION) {
                $this->fail('ManifestManager load returned wrong format_version');
            }

            $entities = $manager->getEntities($loaded);
            if (count($entities) !== 2 || $entities[0]->value !== 'posts') {
                $this->fail('ManifestManager::getEntities mismatch');
            }

            $defs = $manager->getEntityDefinitions($loaded);
            if (($defs['cpt:portfolio']->label ?? '') !== 'Portfolio') {
                $this->fail('ManifestManager::getEntityDefinitions mismatch');
            }

            if ($manager->getChunks($loaded, $postKey) !== ['posts_0001.json']) {
                $this->fail('ManifestManager::getChunks mismatch');
            }

            try {
                $bad = ['format_version' => '99.0.0'];
                file_put_contents($tmp . DIRECTORY_SEPARATOR . 'manifest.json', $serializer->encode($bad));
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

    private function testManifestBackwardCompat(): void
    {
        $serializer = new Serializer();
        $manager = new ManifestManager($serializer, new SchemaRegistry());
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'superexport_manifest_old_' . uniqid();
        mkdir($tmp, 0775, true);

        try {
            $oldManifest = [
                'format_version' => '1.0.0',
                'exported_at' => '2024-01-01T00:00:00Z',
                'source' => ['cms' => 'wordpress', 'cms_version' => '6.0', 'site_url' => null, 'db_prefix' => 'wp_'],
                'schema' => [
                    'entities' => ['posts'],
                    'fields' => ['posts' => ['source_id' => ['type' => 'scalar', 'required' => true]]],
                ],
                'stats' => ['posts' => 1],
                'source_field_map' => [],
                'chunks' => ['posts' => ['posts_0001.json']],
            ];
            $manager->save($tmp, $oldManifest);
            $loaded = $manager->load($tmp);
            $defs = $manager->getEntityDefinitions($loaded);
            if (!isset($defs['posts'])) {
                $this->fail('Manifest 1.0.x backward compat: missing posts definition fallback');
            }
        } finally {
            @unlink($tmp . DIRECTORY_SEPARATOR . 'manifest.json');
            @rmdir($tmp);
        }
    }

    private function testEntityMapper(): void
    {
        $mapper = new EntityMapper();
        $sourceKey = EntityKey::iblock(5);
        $sourceDef = new EntityDefinition(
            key: $sourceKey,
            kind: EntityDefinition::KIND_CONTENT,
            label: 'Services',
            canonicalKind: EntityDefinition::CANONICAL_POST,
            source: ['cms' => 'bitrix', 'iblock_id' => 5],
        );

        $target = new class implements \SuperExport\Contracts\CmsAdapterInterface {
            public function getName(): string { return 'wordpress'; }
            public function detect(string $rootPath): bool { return true; }
            public function probeDetection(string $rootPath): array { return ['detected' => true, 'checks' => []]; }
            public function getCmsVersion(): ?string { return null; }
            public function getSiteUrl(): ?string { return null; }
            public function getDbPrefix(): ?string { return 'wp_'; }
            public function getSupportedEntities(): array {
                return [EntityKey::fromStandard(EntityType::Post), EntityKey::fromStandard(EntityType::Page)];
            }
            public function getEntityDefinitions(): array {
                return [
                    'posts' => EntityDefinition::forStandard(EntityType::Post),
                    'pages' => EntityDefinition::forStandard(EntityType::Page),
                ];
            }
            public function countEntities(EntityKey $key): int { return 0; }
            public function exportEntities(EntityKey $key, int $batchSize): \Generator { if (false) { yield; } }
            public function getFieldMap(): array { return []; }
            public function importEntities(EntityKey $key, array $entities, \SuperExport\Contracts\ImportContextInterface $context): \SuperExport\Contracts\ImportBatchResult {
                return new \SuperExport\Contracts\ImportBatchResult();
            }
        };

        $mapped = $mapper->map($sourceKey, $sourceDef, $target, ['iblock:5' => $sourceDef]);
        if ($mapped === null || $mapped->value !== 'posts') {
            $this->fail('EntityMapper should map iblock:5 to posts on WordPress');
        }
    }

    private function fail(string $message): void
    {
        $this->failures[] = $message;
    }
}
