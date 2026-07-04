<?php

declare(strict_types=1);

namespace SuperExport\Adapters\Modx;

use PDO;
use SuperExport\Adapters\AbstractPdoAdapter;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\Entity\Page;
use SuperExport\Universal\Entity\Post;
use SuperExport\Universal\EntityType;

final class ModxAdapter extends AbstractPdoAdapter
{
    public function getName(): string
    {
        return 'modx';
    }

    protected function canDetectByFiles(string $rootPath): bool
    {
        return is_file($rootPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.inc.php');
    }

    protected function canDetectByTables(): bool
    {
        return $this->tableExists('site_content');
    }

    protected function connectFromCms(string $rootPath): PDO
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.inc.php';
        $content = (string) file_get_contents($configPath);

        $database = $this->extractModxVar($content, 'database') ?? '';
        $user = $this->extractModxVar($content, 'username') ?? '';
        $password = $this->extractModxVar($content, 'password') ?? '';
        $host = $this->extractModxVar($content, 'host') ?? 'localhost';
        $prefix = $this->extractModxVar($content, 'table_prefix') ?? 'modx_';
        $this->dbPrefix = $prefix;

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function readCmsMetadata(string $rootPath): void
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.inc.php';
        $content = (string) file_get_contents($configPath);
        $this->dbPrefix = $this->extractModxVar($content, 'table_prefix') ?? 'modx_';
        $siteUrl = $this->extractModxVar($content, 'site_url');
        $this->siteUrl = $siteUrl ? rtrim($siteUrl, '/') : null;
    }

    private function extractModxVar(string $content, string $key): ?string
    {
        if (preg_match('/\$' . preg_quote($key, '/') . '\s*=\s*[\'"]([^\'"]*)[\'"]/', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return list<EntityType> */
    public function getSupportedEntities(): array
    {
        return [EntityType::Post, EntityType::Page];
    }

    public function countEntities(EntityType $type): int
    {
        return match ($type) {
            EntityType::Post => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('site_content') . " WHERE deleted = 0 AND class_key = 'modDocument'",
            ),
            EntityType::Page => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('site_content') . ' WHERE deleted = 0 AND isfolder = 1',
            ),
            default => 0,
        };
    }

    public function exportEntities(EntityType $type, int $batchSize): \Generator
    {
        return match ($type) {
            EntityType::Post => $this->exportContent(false, $batchSize),
            EntityType::Page => $this->exportContent(true, $batchSize),
            default => $this->emptyGenerator(),
        };
    }

    /** @return array<string, string> */
    public function getFieldMap(): array
    {
        return [
            'modx.pagetitle' => 'title',
            'modx.content' => 'body',
            'modx.introtext' => 'excerpt',
            'modx.alias' => 'slug',
            'modx.published' => 'status',
        ];
    }

    public function importEntities(EntityType $type, array $entities, ImportContextInterface $context): ImportBatchResult
    {
        if ($context->isDryRun()) {
            return $this->dryRunResult($entities);
        }

        return match ($type) {
            EntityType::Post, EntityType::Page => $this->importContent($entities, $type === EntityType::Page, $context),
            default => new ImportBatchResult(),
        };
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportContent(bool $isFolder, int $batchSize): \Generator
    {
        $sql = 'SELECT id, pagetitle, alias, content, introtext, published, createdon, editedon, parent, menuindex
                FROM ' . $this->table('site_content') . '
                WHERE deleted = 0 AND isfolder = :folder
                ORDER BY id';

        foreach ($this->batchedQuery($sql, $batchSize, ['folder' => $isFolder ? 1 : 0]) as $row) {
            $id = (int) $row['id'];
            $status = ((int) ($row['published'] ?? 0)) === 1 ? 'published' : 'draft';
            $common = [
                'sourceId' => $id,
                'slug' => (string) ($row['alias'] ?: 'doc-' . $id),
                'title' => (string) $row['pagetitle'],
                'status' => $status,
                'body' => (string) ($row['content'] ?? ''),
                'excerpt' => (string) ($row['introtext'] ?? ''),
                'publishedAt' => $this->isoDateFromTimestamp((int) ($row['createdon'] ?? 0)),
                'updatedAt' => $this->isoDateFromTimestamp((int) ($row['editedon'] ?? 0)),
                'parentId' => (int) ($row['parent'] ?? 0) ?: null,
                'sortOrder' => (int) ($row['menuindex'] ?? 0),
                'meta' => $this->loadTemplateVars($id),
            ];

            $entity = $isFolder ? new Page(...$common) : new Post(...$common);
            yield $entity->toArray();
        }
    }

    /** @return list<array{key: string, value: mixed, type: string}> */
    private function loadTemplateVars(int $contentId): array
    {
        $sql = 'SELECT tv.name, tvc.value
                FROM ' . $this->table('site_tmplvar_contentvalues') . ' tvc
                JOIN ' . $this->table('site_tmplvars') . ' tv ON tv.id = tvc.tmplvarid
                WHERE tvc.contentid = :id';

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['id' => $contentId]);
        $meta = [];
        foreach ($stmt->fetchAll() as $row) {
            $meta[] = [
                'key' => (string) $row['name'],
                'value' => $row['value'],
                'type' => 'string',
            ];
        }

        return $meta;
    }

  /** @param list<array<string, mixed>> $entities */
    private function importContent(array $entities, bool $isFolder, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $now = time();

        foreach ($entities as $entity) {
            $parentId = 0;
            if (!empty($entity['parent_id'])) {
                $resolved = $context->getIdRemapper()->resolve(
                    $isFolder ? EntityType::Page : EntityType::Post,
                    $entity['parent_id'],
                );
                $parentId = $resolved !== null ? (int) $resolved : 0;
            }

            $published = ($entity['status'] ?? 'draft') === 'published' ? 1 : 0;

            $pdo->prepare(
                'INSERT INTO ' . $this->table('site_content') . ' (pagetitle, alias, content, introtext, parent, isfolder,
                 published, createdon, editedon, menuindex, deleted, class_key)
                 VALUES (:title, :alias, :body, :excerpt, :parent, :folder, :published, :created, :edited, :sort, 0, \'modDocument\')',
            )->execute([
                'title' => $entity['title'],
                'alias' => $entity['slug'],
                'body' => $entity['body'] ?? '',
                'excerpt' => $entity['excerpt'] ?? '',
                'parent' => $parentId,
                'folder' => $isFolder ? 1 : 0,
                'published' => $published,
                'created' => $now,
                'edited' => $now,
                'sort' => $entity['sort_order'] ?? 0,
            ]);
            $targetId = (int) $pdo->lastInsertId();

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $targetId],
            ));
        }

        return $result;
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
