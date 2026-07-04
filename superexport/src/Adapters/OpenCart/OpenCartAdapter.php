<?php

declare(strict_types=1);

namespace SuperExport\Adapters\OpenCart;

use PDO;
use SuperExport\Adapters\AbstractPdoAdapter;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\Entity\Category;
use SuperExport\Universal\Entity\Product;
use SuperExport\Universal\EntityType;

final class OpenCartAdapter extends AbstractPdoAdapter
{
    private int $languageId = 1;

    public function getName(): string
    {
        return 'opencart';
    }

    protected function canDetectByFiles(string $rootPath): bool
    {
        return is_file($rootPath . DIRECTORY_SEPARATOR . 'config.php')
            || is_file($rootPath . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'config.php');
    }

    protected function canDetectByTables(): bool
    {
        return $this->tableExists('product');
    }

    /** @return list<array{path: string, label: string, type?: 'file'|'dir'}> */
    protected function getDetectionFileMarkers(): array
    {
        return [
            ['path' => 'config.php', 'label' => 'config.php'],
            ['path' => 'admin/config.php', 'label' => 'admin/config.php'],
        ];
    }

    /** @return list<array{table: string, label: string}> */
    protected function getDetectionTableMarkers(): array
    {
        $prefix = $this->dbPrefix ?? 'oc_';

        return [['table' => 'product', 'label' => $prefix . 'product']];
    }

    protected function connectFromCms(string $rootPath): PDO
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_file($configPath)) {
            $configPath = $rootPath . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'config.php';
        }

        $content = (string) file_get_contents($configPath);
        $defines = self::parsePhpDefines($content, ['DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PREFIX']);
        $this->dbPrefix = $defines['DB_PREFIX'] ?? 'oc_';

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $defines['DB_HOSTNAME'] ?? 'localhost',
            $defines['DB_DATABASE'] ?? '',
        );

        return new PDO($dsn, $defines['DB_USERNAME'] ?? '', $defines['DB_PASSWORD'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function readCmsMetadata(string $rootPath): void
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($configPath)) {
            $content = (string) file_get_contents($configPath);
            if (preg_match("/define\s*\(\s*'HTTP_SERVER'\s*,\s*'([^']+)'/", $content, $m)) {
                $this->siteUrl = rtrim($m[1], '/');
            }
            if (preg_match("/define\s*\(\s*'DB_PREFIX'\s*,\s*'([^']+)'/", $content, $m)) {
                $this->dbPrefix = $m[1];
            }
        }

        $this->languageId = $this->scalarCount(
            'SELECT language_id FROM ' . $this->table('language') . ' ORDER BY language_id LIMIT 1',
        ) ?: 1;
    }

    /** @return list<EntityType> */
    public function getSupportedEntities(): array
    {
        return [EntityType::Category, EntityType::Product];
    }

    public function countEntities(EntityType $type): int
    {
        return match ($type) {
            EntityType::Product => $this->scalarCount('SELECT COUNT(*) FROM ' . $this->table('product')),
            EntityType::Category => $this->scalarCount('SELECT COUNT(*) FROM ' . $this->table('category')),
            default => 0,
        };
    }

    public function exportEntities(EntityType $type, int $batchSize): \Generator
    {
        return match ($type) {
            EntityType::Product => $this->exportProducts($batchSize),
            EntityType::Category => $this->exportCategories($batchSize),
            default => $this->emptyGenerator(),
        };
    }

    /** @return array<string, string> */
    public function getFieldMap(): array
    {
        return [
            'opencart.product_description.name' => 'title',
            'opencart.product_description.description' => 'body',
            'opencart.product_description.meta_description' => 'excerpt',
        ];
    }

    public function importEntities(EntityType $type, array $entities, ImportContextInterface $context): ImportBatchResult
    {
        if ($context->isDryRun()) {
            return $this->dryRunResult($entities);
        }

        return match ($type) {
            EntityType::Product => $this->importProducts($entities, $context),
            EntityType::Category => $this->importCategories($entities, $context),
            default => new ImportBatchResult(),
        };
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportProducts(int $batchSize): \Generator
    {
        $sql = 'SELECT p.product_id, p.model, p.sku, p.price, p.status, p.date_added, p.date_modified,
                       pd.name, pd.description, pd.meta_description
                FROM ' . $this->table('product') . ' p
                JOIN ' . $this->table('product_description') . ' pd ON pd.product_id = p.product_id
                WHERE pd.language_id = :lang
                ORDER BY p.product_id';

        foreach ($this->batchedQuery($sql, $batchSize, ['lang' => $this->languageId]) as $row) {
            $id = (int) $row['product_id'];
            $slug = $this->slugify((string) $row['model'] ?: (string) $row['name']);
            $meta = [
                ['key' => 'sku', 'value' => $row['sku'] ?? '', 'type' => 'string'],
                ['key' => 'price', 'value' => $row['price'] ?? '0', 'type' => 'string'],
            ];

            yield (new Product(
                sourceId: $id,
                slug: $slug,
                title: (string) $row['name'],
                status: ((int) ($row['status'] ?? 0)) === 1 ? 'published' : 'draft',
                body: (string) ($row['description'] ?? ''),
                excerpt: (string) ($row['meta_description'] ?? ''),
                publishedAt: $this->isoDate($row['date_added'] ?? null),
                updatedAt: $this->isoDate($row['date_modified'] ?? null),
                taxonomyRefs: $this->loadProductCategories($id),
                meta: $meta,
            ))->toArray();
        }
    }

    /** @return list<array{type: string, source_id: int|string}> */
    private function loadProductCategories(int $productId): array
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT category_id FROM ' . $this->table('product_to_category') . ' WHERE product_id = :id',
        );
        $stmt->execute(['id' => $productId]);
        $refs = [];
        foreach ($stmt->fetchAll() as $row) {
            $refs[] = ['type' => 'category', 'source_id' => (int) $row['category_id']];
        }

        return $refs;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportCategories(int $batchSize): \Generator
    {
        $sql = 'SELECT c.category_id, c.parent_id, cd.name, cd.description
                FROM ' . $this->table('category') . ' c
                JOIN ' . $this->table('category_description') . ' cd ON cd.category_id = c.category_id
                WHERE cd.language_id = :lang
                ORDER BY c.category_id';

        foreach ($this->batchedQuery($sql, $batchSize, ['lang' => $this->languageId]) as $row) {
            yield (new Category(
                sourceId: (int) $row['category_id'],
                slug: $this->slugify((string) $row['name']),
                name: (string) $row['name'],
                type: 'category',
                description: (string) ($row['description'] ?? ''),
                parentId: (int) ($row['parent_id'] ?? 0) ?: null,
            ))->toArray();
        }
    }

    /** @param list<array<string, mixed>> $entities */
    private function importProducts(array $entities, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $now = date('Y-m-d H:i:s');

        foreach ($entities as $entity) {
            $pdo->prepare(
                'INSERT INTO ' . $this->table('product') . ' (model, sku, price, quantity, status, date_added, date_modified)
                 VALUES (:model, :sku, :price, 0, :status, :added, :modified)',
            )->execute([
                'model' => $entity['slug'],
                'sku' => $this->metaValue($entity, 'sku'),
                'price' => $this->metaValue($entity, 'price') ?: '0',
                'status' => ($entity['status'] ?? 'draft') === 'published' ? 1 : 0,
                'added' => $now,
                'modified' => $now,
            ]);
            $productId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO ' . $this->table('product_description') . ' (product_id, language_id, name, description, meta_description)
                 VALUES (:id, :lang, :name, :body, :excerpt)',
            )->execute([
                'id' => $productId,
                'lang' => $this->languageId,
                'name' => $entity['title'],
                'body' => $entity['body'] ?? '',
                'excerpt' => $entity['excerpt'] ?? '',
            ]);

            foreach ($entity['taxonomy_refs'] ?? [] as $ref) {
                $catId = $context->getIdRemapper()->resolve(EntityType::Category, $ref['source_id']);
                if ($catId !== null) {
                    $pdo->prepare(
                        'INSERT INTO ' . $this->table('product_to_category') . ' (product_id, category_id) VALUES (:p, :c)',
                    )->execute(['p' => $productId, 'c' => $catId]);
                }
            }

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
            $parentId = 0;
            if (!empty($entity['parent_id'])) {
                $resolved = $context->getIdRemapper()->resolve(EntityType::Category, $entity['parent_id']);
                $parentId = $resolved !== null ? (int) $resolved : 0;
            }

            $pdo->prepare(
                'INSERT INTO ' . $this->table('category') . ' (parent_id, top, column, sort_order, status, date_added, date_modified)
                 VALUES (:parent, 0, 1, 0, 1, NOW(), NOW())',
            )->execute(['parent' => $parentId]);
            $categoryId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO ' . $this->table('category_description') . ' (category_id, language_id, name, description)
                 VALUES (:id, :lang, :name, :desc)',
            )->execute([
                'id' => $categoryId,
                'lang' => $this->languageId,
                'name' => $entity['name'],
                'desc' => $entity['description'] ?? '',
            ]);

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $categoryId],
            ));
        }

        return $result;
    }

    /** @param array<string, mixed> $entity */
    private function metaValue(array $entity, string $key): string
    {
        foreach ($entity['meta'] ?? [] as $item) {
            if (($item['key'] ?? '') === $key) {
                return (string) ($item['value'] ?? '');
            }
        }

        return '';
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '', '-'));

        return $slug !== '' ? $slug : 'item';
    }

    /** @return \Generator<int, never> */
    private function emptyGenerator(): \Generator
    {
        if (false) {
            yield;
        }
    }
}
