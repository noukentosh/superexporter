<?php

declare(strict_types=1);

namespace SuperExport\Adapters\Drupal;

use PDO;
use SuperExport\Adapters\AbstractPdoAdapter;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\Entity\Category;
use SuperExport\Universal\Entity\Page;
use SuperExport\Universal\Entity\Post;
use SuperExport\Universal\Entity\Product;
use SuperExport\Universal\EntityType;

final class DrupalAdapter extends AbstractPdoAdapter
{
    private bool $hasCommerce = false;

    public function getName(): string
    {
        return 'drupal';
    }

    protected function canDetectByFiles(string $rootPath): bool
    {
        return is_file($rootPath . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'settings.php')
            || is_file($rootPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Drupal.php');
    }

    protected function canDetectByTables(): bool
    {
        return $this->tableExists('node_field_data');
    }

    /** @return list<array{path: string, label: string, type?: 'file'|'dir'}> */
    protected function getDetectionFileMarkers(): array
    {
        return [
            ['path' => 'sites/default/settings.php', 'label' => 'sites/default/settings.php'],
            ['path' => 'core/lib/Drupal.php', 'label' => 'core/lib/Drupal.php'],
        ];
    }

    /** @return list<array{table: string, label: string}> */
    protected function getDetectionTableMarkers(): array
    {
        return [['table' => 'node_field_data', 'label' => 'node_field_data']];
    }

    protected function connectFromCms(string $rootPath): PDO
    {
        $settingsPath = $rootPath . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'settings.php';
        $content = (string) file_get_contents($settingsPath);

        $db = $this->extractDrupalDb($content);
        if ($db === null) {
            throw new SuperExportException('Drupal database settings not found.');
        }

        $this->dbPrefix = '';
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $db['host'] ?? 'localhost',
            $db['database'] ?? '',
        );

        return new PDO($dsn, (string) ($db['username'] ?? ''), (string) ($db['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** @return array{host?: string, database?: string, username?: string, password?: string}|null */
    private function extractDrupalDb(string $content): ?array
    {
        if (!preg_match('/\$databases\s*\[\s*[\'"]default[\'"]\s*\]\s*\[\s*[\'"]default[\'"]\s*\]\s*=\s*array\s*\((.*?)\);/s', $content, $m)) {
            return null;
        }

        $block = $m[1];
        $out = [];
        foreach (['database', 'username', 'password', 'host', 'port'] as $key) {
            if (preg_match('/[\'"]' . $key . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', $block, $km)) {
                $out[$key] = $km[1];
            }
        }

        return $out !== [] ? $out : null;
    }

    protected function readCmsMetadata(string $rootPath): void
    {
        $this->dbPrefix = '';
        $this->hasCommerce = $this->tableExists('commerce_product_field_data');
    }

    /** @return list<EntityType> */
    public function getSupportedEntities(): array
    {
        $entities = [EntityType::Category, EntityType::Post, EntityType::Page];
        if ($this->hasCommerce) {
            $entities[] = EntityType::Product;
        }

        return $entities;
    }

    public function countEntities(EntityType $type): int
    {
        return match ($type) {
            EntityType::Post => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('node_field_data') . " WHERE type = 'article' AND status = 1",
            ),
            EntityType::Page => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('node_field_data') . " WHERE type = 'page'",
            ),
            EntityType::Product => $this->hasCommerce ? $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('commerce_product_field_data'),
            ) : 0,
            EntityType::Category => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('taxonomy_term_field_data'),
            ),
            default => 0,
        };
    }

    public function exportEntities(EntityType $type, int $batchSize): \Generator
    {
        return match ($type) {
            EntityType::Post => $this->exportNodes('article', $batchSize),
            EntityType::Page => $this->exportNodes('page', $batchSize),
            EntityType::Product => $this->exportProducts($batchSize),
            EntityType::Category => $this->exportCategories($batchSize),
            default => $this->emptyGenerator(),
        };
    }

    /** @return array<string, string> */
    public function getFieldMap(): array
    {
        return [
            'drupal.node_field_data.title' => 'title',
            'drupal.body.value' => 'body',
            'drupal.path.alias' => 'slug',
            'drupal.node_field_data.status' => 'status',
        ];
    }

    public function importEntities(EntityType $type, array $entities, ImportContextInterface $context): ImportBatchResult
    {
        if ($context->isDryRun()) {
            return $this->dryRunResult($entities);
        }

        return match ($type) {
            EntityType::Post => $this->importNodes($entities, 'article', $context),
            EntityType::Page => $this->importNodes($entities, 'page', $context),
            EntityType::Product => $this->importProducts($entities, $context),
            EntityType::Category => $this->importCategories($entities, $context),
            default => new ImportBatchResult(),
        };
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportNodes(string $bundle, int $batchSize): \Generator
    {
        $sql = 'SELECT nfd.nid, nfd.title, nfd.status, nfd.created, nfd.changed, pa.alias
                FROM ' . $this->table('node_field_data') . ' nfd
                LEFT JOIN ' . $this->table('path_alias') . ' pa ON pa.path = CONCAT(\'/node/\', nfd.nid)
                WHERE nfd.type = :type
                ORDER BY nfd.nid';

        foreach ($this->batchedQuery($sql, $batchSize, ['type' => $bundle]) as $row) {
            $id = (int) $row['nid'];
            $body = $this->loadBodyField($id);
            $slug = $this->aliasToSlug((string) ($row['alias'] ?? '')) ?: 'node-' . $id;
            $status = ((int) ($row['status'] ?? 0)) === 1 ? 'published' : 'draft';

            $common = [
                'sourceId' => $id,
                'slug' => $slug,
                'title' => (string) $row['title'],
                'status' => $status,
                'body' => $body,
                'publishedAt' => $this->isoDateFromTimestamp((int) ($row['created'] ?? 0)),
                'updatedAt' => $this->isoDateFromTimestamp((int) ($row['changed'] ?? 0)),
                'taxonomyRefs' => $this->loadNodeTerms($id),
            ];

            $entity = $bundle === 'page' ? new Page(...$common) : new Post(...$common);
            yield $entity->toArray();
        }
    }

    private function loadBodyField(int $nid): string
    {
        if (!$this->tableExists('node__body')) {
            return '';
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT body_value FROM ' . $this->table('node__body') . ' WHERE entity_id = :id LIMIT 1',
        );
        $stmt->execute(['id' => $nid]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : '';
    }

    /** @return list<array{type: string, source_id: int|string}> */
    private function loadNodeTerms(int $nid): array
    {
        if (!$this->tableExists('taxonomy_index')) {
            return [];
        }

        $stmt = $this->getPdo()->prepare(
            'SELECT tid FROM ' . $this->table('taxonomy_index') . ' WHERE nid = :id',
        );
        $stmt->execute(['id' => $nid]);
        $refs = [];
        foreach ($stmt->fetchAll() as $row) {
            $refs[] = ['type' => 'category', 'source_id' => (int) $row['tid']];
        }

        return $refs;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportProducts(int $batchSize): \Generator
    {
        if (!$this->hasCommerce) {
            return;
        }

        $sql = 'SELECT product_id, title, status, created, changed FROM '
            . $this->table('commerce_product_field_data') . ' ORDER BY product_id';

        foreach ($this->batchedQuery($sql, $batchSize) as $row) {
            $id = (int) $row['product_id'];
            yield (new Product(
                sourceId: $id,
                slug: 'product-' . $id,
                title: (string) $row['title'],
                status: ((int) ($row['status'] ?? 0)) === 1 ? 'published' : 'draft',
                publishedAt: $this->isoDateFromTimestamp((int) ($row['created'] ?? 0)),
                updatedAt: $this->isoDateFromTimestamp((int) ($row['changed'] ?? 0)),
            ))->toArray();
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportCategories(int $batchSize): \Generator
    {
        $sql = 'SELECT tid, name, description__value, vid FROM '
            . $this->table('taxonomy_term_field_data') . ' ORDER BY tid';

        foreach ($this->batchedQuery($sql, $batchSize) as $row) {
            yield (new Category(
                sourceId: (int) $row['tid'],
                slug: $this->slugify((string) $row['name']),
                name: (string) $row['name'],
                type: (string) ($row['vid'] ?? 'category'),
                description: (string) ($row['description__value'] ?? ''),
            ))->toArray();
        }
    }

    /** @param list<array<string, mixed>> $entities */
    private function importNodes(array $entities, string $bundle, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $now = time();
        $uuid = static fn (): string => sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );

        foreach ($entities as $entity) {
            $nodeUuid = $uuid();
            $pdo->prepare(
                'INSERT INTO ' . $this->table('node') . ' (type, uuid, langcode) VALUES (:type, :uuid, \'en\')',
            )->execute(['type' => $bundle, 'uuid' => $nodeUuid]);
            $nid = (int) $pdo->lastInsertId();

            $status = ($entity['status'] ?? 'draft') === 'published' ? 1 : 0;
            $pdo->prepare(
                'INSERT INTO ' . $this->table('node_field_data') . ' (nid, vid, type, langcode, status, uid, title, created, changed, promote, sticky, default_langcode)
                 VALUES (:nid, :vid, :type, \'en\', :status, 1, :title, :created, :changed, 1, 0, 1)',
            )->execute([
                'nid' => $nid,
                'vid' => $nid,
                'type' => $bundle,
                'status' => $status,
                'title' => $entity['title'],
                'created' => $now,
                'changed' => $now,
            ]);

            if ($this->tableExists('node__body') && !empty($entity['body'])) {
                $pdo->prepare(
                    'INSERT INTO ' . $this->table('node__body') . ' (bundle, deleted, entity_id, revision_id, langcode, delta, body_value, body_format)
                     VALUES (:bundle, 0, :nid, :vid, \'en\', 0, :body, \'basic_html\')',
                )->execute([
                    'bundle' => $bundle,
                    'nid' => $nid,
                    'vid' => $nid,
                    'body' => $entity['body'],
                ]);
            }

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $nid],
            ));
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $entities */
    private function importProducts(array $entities, ImportContextInterface $context): ImportBatchResult
    {
        if (!$this->hasCommerce) {
            return new ImportBatchResult(errors: ['Commerce module not available.']);
        }

        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $now = time();

        foreach ($entities as $entity) {
            $pdo->prepare(
                'INSERT INTO ' . $this->table('commerce_product_field_data') . ' (product_id, type, langcode, status, uid, title, created, changed, default_langcode)
                 VALUES (NULL, \'default\', \'en\', :status, 1, :title, :created, :changed, 1)',
            )->execute([
                'status' => ($entity['status'] ?? 'draft') === 'published' ? 1 : 0,
                'title' => $entity['title'],
                'created' => $now,
                'changed' => $now,
            ]);
            $productId = (int) $pdo->lastInsertId();

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $productId],
            ));
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $entities */
    private function importCategories(array $entities, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();

        foreach ($entities as $entity) {
            $pdo->prepare(
                'INSERT INTO ' . $this->table('taxonomy_term_data') . ' (vid, uuid, langcode) VALUES (:vid, UUID(), \'en\')',
            )->execute(['vid' => $entity['type'] ?? 'category']);
            $tid = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO ' . $this->table('taxonomy_term_field_data') . ' (tid, vid, langcode, name, description__value, description__format, weight, changed, default_langcode)
                 VALUES (:tid, :vid, \'en\', :name, :desc, \'basic_html\', 0, UNIX_TIMESTAMP(), 1)',
            )->execute([
                'tid' => $tid,
                'vid' => $entity['type'] ?? 'category',
                'name' => $entity['name'],
                'desc' => $entity['description'] ?? '',
            ]);

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $tid],
            ));
        }

        return $result;
    }

    private function aliasToSlug(string $alias): string
    {
        return ltrim($alias, '/');
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '', '-'));

        return $slug !== '' ? $slug : 'term';
    }

    private function isoDateFromTimestamp(int $ts): ?string
    {
        return $ts > 0 ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }

    /** @return \Generator<int, never> */
    private function emptyGenerator(): \Generator
    {
        if (false) {
            yield;
        }
    }
}
