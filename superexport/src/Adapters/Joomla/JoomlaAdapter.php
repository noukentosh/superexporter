<?php

declare(strict_types=1);

namespace SuperExport\Adapters\Joomla;

use PDO;
use SuperExport\Adapters\AbstractPdoAdapter;
use SuperExport\Adapters\StandardEntitySupport;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\Entity\Category;
use SuperExport\Universal\Entity\Page;
use SuperExport\Universal\Entity\Post;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\EntityType;

final class JoomlaAdapter extends AbstractPdoAdapter
{
    use StandardEntitySupport;
    public function getName(): string
    {
        return 'joomla';
    }

    protected function canDetectByFiles(string $rootPath): bool
    {
        return is_file($rootPath . DIRECTORY_SEPARATOR . 'configuration.php');
    }

    protected function canDetectByTables(): bool
    {
        return $this->tableExists('content');
    }

    /** @return list<array{path: string, label: string, type?: 'file'|'dir'}> */
    protected function getDetectionFileMarkers(): array
    {
        return [
            ['path' => 'configuration.php', 'label' => 'configuration.php'],
        ];
    }

    /** @return list<array{table: string, label: string}> */
    protected function getDetectionTableMarkers(): array
    {
        $prefix = $this->dbPrefix ?? 'jos_';

        return [['table' => 'content', 'label' => $prefix . 'content']];
    }

    protected function connectFromCms(string $rootPath): PDO
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'configuration.php';
        $content = (string) file_get_contents($configPath);

        $host = $this->extractConfigVar($content, 'host') ?? 'localhost';
        $user = $this->extractConfigVar($content, 'user') ?? '';
        $password = $this->extractConfigVar($content, 'password') ?? '';
        $db = $this->extractConfigVar($content, 'db') ?? '';
        $prefix = $this->extractConfigVar($content, 'dbprefix') ?? 'jos_';
        $this->dbPrefix = $prefix;

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $db);

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function readCmsMetadata(string $rootPath): void
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'configuration.php';
        $content = (string) file_get_contents($configPath);
        $this->dbPrefix = $this->extractConfigVar($content, 'dbprefix') ?? 'jos_';
        $live = $this->extractConfigVar($content, 'live_site');
        $this->siteUrl = $live ? rtrim($live, '/') : null;
    }

    private function extractConfigVar(string $content, string $name): ?string
    {
        if (preg_match('/public\s+\$' . preg_quote($name, '/') . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return list<EntityKey> */
    public function getSupportedEntities(): array
    {
        return $this->keysFromTypes([EntityType::Category, EntityType::Post, EntityType::Page]);
    }

    /** @return array<string, EntityDefinition> */
    public function getEntityDefinitions(): array
    {
        return $this->definitionsFromTypes([EntityType::Category, EntityType::Post, EntityType::Page]);
    }

    public function countEntities(EntityKey $key): int
    {
        $type = $this->toStandardType($key);
        if ($type === null) {
            return 0;
        }

        return match ($type) {
            EntityType::Post => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('content') . ' WHERE state >= 0',
            ),
            EntityType::Page => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('content') . ' WHERE state >= 0 AND alias != \'\'',
            ),
            EntityType::Category => $this->scalarCount('SELECT COUNT(*) FROM ' . $this->table('categories')),
            default => 0,
        };
    }

    public function exportEntities(EntityKey $key, int $batchSize): \Generator
    {
        $type = $this->toStandardType($key);
        if ($type === null) {
            return $this->emptyGenerator();
        }

        return match ($type) {
            EntityType::Post => $this->exportContent('post', $batchSize),
            EntityType::Page => $this->exportContent('page', $batchSize),
            EntityType::Category => $this->exportCategories($batchSize),
            default => $this->emptyGenerator(),
        };
    }

    /** @return array<string, string> */
    public function getFieldMap(): array
    {
        return [
            'joomla.title' => 'title',
            'joomla.introtext' => 'excerpt',
            'joomla.fulltext' => 'body',
            'joomla.alias' => 'slug',
            'joomla.state' => 'status',
        ];
    }

    public function importEntities(EntityKey $key, array $entities, ImportContextInterface $context): ImportBatchResult
    {
        if ($context->isDryRun()) {
            return $this->dryRunResult($entities);
        }

        $type = $this->toStandardType($key);
        if ($type === null) {
            return new ImportBatchResult();
        }

        return match ($type) {
            EntityType::Post, EntityType::Page => $this->importContent($entities, $context),
            EntityType::Category => $this->importCategories($entities, $context),
            default => new ImportBatchResult(),
        };
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportContent(string $kind, int $batchSize): \Generator
    {
        $sql = 'SELECT id, title, alias, introtext, `fulltext`, state, created, modified, catid, created_by_alias
                FROM ' . $this->table('content') . ' WHERE state >= 0 ORDER BY id';

        foreach ($this->batchedQuery($sql, $batchSize) as $row) {
            $id = (int) $row['id'];
            $body = trim((string) ($row['introtext'] ?? '') . "\n" . (string) ($row['fulltext'] ?? ''));
            $status = match ((int) ($row['state'] ?? 0)) {
                1 => 'published',
                2 => 'archived',
                default => 'draft',
            };
            $taxonomyRefs = [];
            if (!empty($row['catid'])) {
                $taxonomyRefs[] = ['type' => 'category', 'source_id' => (int) $row['catid']];
            }

            $common = [
                'sourceId' => $id,
                'slug' => (string) ($row['alias'] ?: 'article-' . $id),
                'title' => (string) $row['title'],
                'status' => $status,
                'body' => $body,
                'excerpt' => (string) ($row['introtext'] ?? ''),
                'authorName' => $row['created_by_alias'] ?? null,
                'publishedAt' => $this->isoDate($row['created'] ?? null),
                'updatedAt' => $this->isoDate($row['modified'] ?? null),
                'taxonomyRefs' => $taxonomyRefs,
            ];

            $entity = $kind === 'page' ? new Page(...$common) : new Post(...$common);
            yield $entity->toArray();
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportCategories(int $batchSize): \Generator
    {
        $sql = 'SELECT id, title, alias, description, parent_id FROM ' . $this->table('categories') . ' ORDER BY id';

        foreach ($this->batchedQuery($sql, $batchSize) as $row) {
            yield (new Category(
                sourceId: (int) $row['id'],
                slug: (string) ($row['alias'] ?: 'cat-' . $row['id']),
                name: (string) $row['title'],
                type: 'category',
                description: (string) ($row['description'] ?? ''),
                parentId: (int) ($row['parent_id'] ?? 0) ?: null,
            ))->toArray();
        }
    }

    /** @param list<array<string, mixed>> $entities */
    private function importContent(array $entities, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $now = date('Y-m-d H:i:s');

        foreach ($entities as $entity) {
            $catId = 0;
            foreach ($entity['taxonomy_refs'] ?? [] as $ref) {
                $resolved = $context->getIdRemapper()->resolve(EntityKey::fromStandard(EntityType::Category), $ref['source_id']);
                if ($resolved !== null) {
                    $catId = (int) $resolved;
                }
            }

            $state = match ($entity['status'] ?? 'draft') {
                'published' => 1,
                'archived' => 2,
                default => 0,
            };

            $pdo->prepare(
                'INSERT INTO ' . $this->table('content') . ' (title, alias, introtext, `fulltext`, state, catid, created, modified, access, language)
                 VALUES (:title, :alias, :intro, :body, :state, :cat, :created, :modified, 1, \'*\')',
            )->execute([
                'title' => $entity['title'],
                'alias' => $entity['slug'],
                'intro' => $entity['excerpt'] ?? '',
                'body' => $entity['body'] ?? '',
                'state' => $state,
                'cat' => $catId,
                'created' => $now,
                'modified' => $now,
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

        foreach ($entities as $entity) {
            $parentId = 0;
            if (!empty($entity['parent_id'])) {
                $resolved = $context->getIdRemapper()->resolve(EntityKey::fromStandard(EntityType::Category), $entity['parent_id']);
                $parentId = $resolved !== null ? (int) $resolved : 0;
            }

            $pdo->prepare(
                'INSERT INTO ' . $this->table('categories') . ' (title, alias, description, parent_id, published, access, extension, language)
                 VALUES (:title, :alias, :desc, :parent, 1, 1, \'com_content\', \'*\')',
            )->execute([
                'title' => $entity['name'],
                'alias' => $entity['slug'],
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
