<?php

declare(strict_types=1);

namespace SuperExport\Adapters\Bitrix;

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

final class BitrixAdapter extends AbstractPdoAdapter
{
    /** @var list<int> */
    private array $newsIblockIds = [];

    /** @var list<int> */
    private array $catalogIblockIds = [];

    public function getName(): string
    {
        return 'bitrix';
    }

    protected function canDetectByFiles(string $rootPath): bool
    {
        return is_dir($rootPath . DIRECTORY_SEPARATOR . 'bitrix')
            && is_file($rootPath . DIRECTORY_SEPARATOR . 'bitrix' . DIRECTORY_SEPARATOR . '.settings.php');
    }

    protected function canDetectByTables(): bool
    {
        return $this->tableExists('iblock_element');
    }

    protected function connectFromCms(string $rootPath): PDO
    {
        $settingsPath = $rootPath . DIRECTORY_SEPARATOR . 'bitrix' . DIRECTORY_SEPARATOR . '.settings.php';
        $settings = require $settingsPath;
        $conn = $settings['connections']['value']['default'] ?? null;
        if (!is_array($conn)) {
            throw new SuperExportException('Bitrix DB settings not found.');
        }

        $this->dbPrefix = '';
        $host = (string) ($conn['host'] ?? 'localhost');
        $database = (string) ($conn['database'] ?? '');
        $dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';

        return new PDO($dsn, (string) ($conn['login'] ?? ''), (string) ($conn['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function readCmsMetadata(string $rootPath): void
    {
        $this->dbPrefix = 'b_';
        $this->siteUrl = null;
        $this->cmsVersion = null;

        if ($this->tableExists('iblock')) {
            $stmt = $this->getPdo()->query(
                'SELECT ID FROM ' . $this->table('iblock') . " WHERE IBLOCK_TYPE_ID IN ('news','content','catalog')",
            );
            foreach ($stmt->fetchAll() as $row) {
                $id = (int) $row['ID'];
                $typeStmt = $this->getPdo()->prepare(
                    'SELECT IBLOCK_TYPE_ID FROM ' . $this->table('iblock') . ' WHERE ID = :id',
                );
                $typeStmt->execute(['id' => $id]);
                $type = (string) $typeStmt->fetchColumn();
                if ($type === 'catalog') {
                    $this->catalogIblockIds[] = $id;
                } else {
                    $this->newsIblockIds[] = $id;
                }
            }
        }
    }

    /** @return list<EntityType> */
    public function getSupportedEntities(): array
    {
        $entities = [EntityType::Category, EntityType::Post, EntityType::Page];
        if ($this->catalogIblockIds !== []) {
            $entities[] = EntityType::Product;
        }

        return $entities;
    }

    public function countEntities(EntityType $type): int
    {
        return match ($type) {
            EntityType::Post => $this->countElements($this->newsIblockIds),
            EntityType::Page => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('iblock_element') . ' WHERE CODE != \'\' AND IBLOCK_ID IN ('
                . $this->idList($this->newsIblockIds) . ')',
            ),
            EntityType::Product => $this->countElements($this->catalogIblockIds),
            EntityType::Category => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('iblock_section'),
            ),
            EntityType::Tag, EntityType::Meta => 0,
        };
    }

    /** @param list<int> $iblockIds */
    private function countElements(array $iblockIds): int
    {
        if ($iblockIds === []) {
            return 0;
        }

        return $this->scalarCount(
            'SELECT COUNT(*) FROM ' . $this->table('iblock_element') . ' WHERE IBLOCK_ID IN (' . $this->idList($iblockIds) . ')',
        );
    }

    /** @param list<int> $ids */
    private function idList(array $ids): string
    {
        return $ids === [] ? '0' : implode(',', array_map('intval', $ids));
    }

    public function exportEntities(EntityType $type, int $batchSize): \Generator
    {
        return match ($type) {
            EntityType::Post => $this->exportElements($this->newsIblockIds, 'post', $batchSize),
            EntityType::Page => $this->exportElements($this->newsIblockIds, 'page', $batchSize),
            EntityType::Product => $this->exportElements($this->catalogIblockIds, 'product', $batchSize),
            EntityType::Category => $this->exportCategories($batchSize),
            EntityType::Tag, EntityType::Meta => $this->emptyGenerator(),
        };
    }

    /** @return array<string, string> */
    public function getFieldMap(): array
    {
        return [
            'bitrix.NAME' => 'title',
            'bitrix.DETAIL_TEXT' => 'body',
            'bitrix.PREVIEW_TEXT' => 'excerpt',
            'bitrix.CODE' => 'slug',
            'bitrix.ACTIVE' => 'status',
        ];
    }

    public function importEntities(EntityType $type, array $entities, ImportContextInterface $context): ImportBatchResult
    {
        if ($context->isDryRun()) {
            return $this->dryRunResult($entities);
        }

        return match ($type) {
            EntityType::Post, EntityType::Page, EntityType::Product => $this->importElements($entities, $type, $context),
            EntityType::Category => $this->importCategories($entities, $context),
            EntityType::Tag, EntityType::Meta => new ImportBatchResult(),
        };
    }

    /** @param list<int> $iblockIds */
    private function exportElements(array $iblockIds, string $kind, int $batchSize): \Generator
    {
        if ($iblockIds === []) {
            return;
        }

        $sql = 'SELECT ID, IBLOCK_ID, NAME, CODE, DETAIL_TEXT, PREVIEW_TEXT, ACTIVE, DATE_CREATE, TIMESTAMP_X,
                       IBLOCK_SECTION_ID, SORT
                FROM ' . $this->table('iblock_element') . '
                WHERE IBLOCK_ID IN (' . $this->idList($iblockIds) . ')
                ORDER BY ID';

        foreach ($this->batchedQuery($sql, $batchSize) as $row) {
            $id = (int) $row['ID'];
            $slug = (string) ($row['CODE'] ?: 'element-' . $id);
            $status = ($row['ACTIVE'] ?? 'Y') === 'Y' ? 'published' : 'draft';
            $sectionId = (int) ($row['IBLOCK_SECTION_ID'] ?? 0) ?: null;
            $taxonomyRefs = $sectionId ? [['type' => 'category', 'source_id' => $sectionId]] : [];
            $meta = $this->loadElementProperties($id);

            $common = [
                'sourceId' => $id,
                'slug' => $slug,
                'title' => (string) $row['NAME'],
                'status' => $status,
                'body' => (string) ($row['DETAIL_TEXT'] ?? ''),
                'excerpt' => (string) ($row['PREVIEW_TEXT'] ?? ''),
                'publishedAt' => $this->isoDate($row['DATE_CREATE'] ?? null),
                'updatedAt' => $this->isoDate($row['TIMESTAMP_X'] ?? null),
                'sortOrder' => (int) ($row['SORT'] ?? 500),
                'taxonomyRefs' => $taxonomyRefs,
                'meta' => $meta,
            ];

            $entity = match ($kind) {
                'page' => new Page(...$common),
                'product' => new Product(...$common),
                default => new Post(...$common),
            };

            yield $entity->toArray();
        }
    }

    /** @return list<array{key: string, value: mixed, type: string}> */
    private function loadElementProperties(int $elementId): array
    {
        $sql = 'SELECT p.CODE, ep.VALUE
                FROM ' . $this->table('iblock_element_property') . ' ep
                JOIN ' . $this->table('iblock_property') . ' p ON p.ID = ep.IBLOCK_PROPERTY_ID
                WHERE ep.IBLOCK_ELEMENT_ID = :id';

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['id' => $elementId]);
        $meta = [];
        foreach ($stmt->fetchAll() as $row) {
            $meta[] = [
                'key' => (string) $row['CODE'],
                'value' => $row['VALUE'],
                'type' => 'string',
            ];
        }

        return $meta;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportCategories(int $batchSize): \Generator
    {
        $sql = 'SELECT ID, CODE, NAME, DESCRIPTION, IBLOCK_SECTION_ID, IBLOCK_ID
                FROM ' . $this->table('iblock_section') . ' ORDER BY ID';

        foreach ($this->batchedQuery($sql, $batchSize) as $row) {
            yield (new Category(
                sourceId: (int) $row['ID'],
                slug: (string) ($row['CODE'] ?: 'section-' . $row['ID']),
                name: (string) $row['NAME'],
                type: 'category',
                description: (string) ($row['DESCRIPTION'] ?? ''),
                parentId: (int) ($row['IBLOCK_SECTION_ID'] ?? 0) ?: null,
            ))->toArray();
        }
    }

    /** @param list<array<string, mixed>> $entities */
    private function importElements(array $entities, EntityType $type, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $iblockId = $type === EntityType::Product
            ? ($this->catalogIblockIds[0] ?? $this->newsIblockIds[0] ?? 1)
            : ($this->newsIblockIds[0] ?? 1);
        $pdo = $this->getPdo();
        $remapper = $context->getIdRemapper();

        foreach ($entities as $entity) {
            $slug = (string) $entity['slug'];
            $sectionId = 0;
            foreach ($entity['taxonomy_refs'] ?? [] as $ref) {
                if (($ref['type'] ?? '') === 'category') {
                    $resolved = $remapper->resolve(EntityType::Category, $ref['source_id']);
                    if ($resolved !== null) {
                        $sectionId = (int) $resolved;
                    }
                }
            }

            $now = date('Y-m-d H:i:s');
            $active = ($entity['status'] ?? 'draft') === 'published' ? 'Y' : 'N';

            $stmt = $pdo->prepare(
                'INSERT INTO ' . $this->table('iblock_element') . ' (IBLOCK_ID, NAME, CODE, DETAIL_TEXT, PREVIEW_TEXT,
                 ACTIVE, DATE_CREATE, TIMESTAMP_X, IBLOCK_SECTION_ID, SORT)
                 VALUES (:iblock, :name, :code, :body, :excerpt, :active, :created, :updated, :section, :sort)',
            );
            $stmt->execute([
                'iblock' => $iblockId,
                'name' => $entity['title'],
                'code' => $slug,
                'body' => $entity['body'] ?? '',
                'excerpt' => $entity['excerpt'] ?? '',
                'active' => $active,
                'created' => $now,
                'updated' => $now,
                'section' => $sectionId,
                'sort' => $entity['sort_order'] ?? 500,
            ]);
            $targetId = (int) $pdo->lastInsertId();

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $targetId],
            ));
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $entities */
    private function importCategories(array $entities, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $iblockId = $this->newsIblockIds[0] ?? $this->catalogIblockIds[0] ?? 1;

        foreach ($entities as $entity) {
            $parentId = 0;
            if (!empty($entity['parent_id'])) {
                $resolved = $context->getIdRemapper()->resolve(EntityType::Category, $entity['parent_id']);
                $parentId = $resolved !== null ? (int) $resolved : 0;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO ' . $this->table('iblock_section') . ' (IBLOCK_ID, NAME, CODE, DESCRIPTION, IBLOCK_SECTION_ID)
                 VALUES (:iblock, :name, :code, :desc, :parent)',
            );
            $stmt->execute([
                'iblock' => $iblockId,
                'name' => $entity['name'],
                'code' => $entity['slug'],
                'desc' => $entity['description'] ?? '',
                'parent' => $parentId,
            ]);
            $targetId = (int) $pdo->lastInsertId();

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $targetId],
            ));
        }

        return $result;
    }

    /** @return \Generator<int, never> */
    private function emptyGenerator(): \Generator
    {
        if (false) {
            yield;
        }
    }
}
