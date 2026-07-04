<?php

declare(strict_types=1);

namespace SuperExport\Adapters\WordPress;

use PDO;
use SuperExport\Adapters\AbstractPdoAdapter;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\Entity\Category;
use SuperExport\Universal\Entity\Page;
use SuperExport\Universal\Entity\Post;
use SuperExport\Universal\Entity\Product;
use SuperExport\Universal\Entity\Tag;
use SuperExport\Universal\EntityType;

final class WordPressAdapter extends AbstractPdoAdapter
{
    private bool $hasWooCommerce = false;

    public function getName(): string
    {
        return 'wordpress';
    }

    protected function canDetectByFiles(string $rootPath): bool
    {
        return is_file($rootPath . DIRECTORY_SEPARATOR . 'wp-config.php')
            || is_file($rootPath . DIRECTORY_SEPARATOR . 'wp-config-sample.php');
    }

    protected function canDetectByTables(): bool
    {
        return $this->tableExists('posts');
    }

    protected function connectFromCms(string $rootPath): PDO
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'wp-config.php';
        if (!is_file($configPath)) {
            throw new SuperExportException('wp-config.php not found.');
        }

        $content = (string) file_get_contents($configPath);
        $defines = self::parsePhpDefines($content, ['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST']);
        if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
            $this->dbPrefix = $m[1];
        } else {
            $this->dbPrefix = 'wp_';
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $defines['DB_HOST'] ?? 'localhost',
            $defines['DB_NAME'] ?? '',
        );

        return new PDO($dsn, $defines['DB_USER'] ?? '', $defines['DB_PASSWORD'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    protected function readCmsMetadata(string $rootPath): void
    {
        $versionFile = $rootPath . DIRECTORY_SEPARATOR . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php';
        if (is_file($versionFile)) {
            $content = (string) file_get_contents($versionFile);
            if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                $this->cmsVersion = $m[1];
            }
        }

        $configPath = $rootPath . DIRECTORY_SEPARATOR . 'wp-config.php';
        if (is_file($configPath)) {
            $content = (string) file_get_contents($configPath);
            if (preg_match('/define\s*\(\s*[\'"]WP_HOME[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                $this->siteUrl = rtrim($m[1], '/');
            } elseif (preg_match('/define\s*\(\s*[\'"]WP_SITEURL[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                $this->siteUrl = rtrim($m[1], '/');
            }
            if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $content, $m)) {
                $this->dbPrefix = $m[1];
            }
        }

        $this->hasWooCommerce = $this->tableExists('posts')
            && $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('posts') . " WHERE post_type = 'product'",
            ) > 0;
    }

  /** @return list<EntityType> */
    public function getSupportedEntities(): array
    {
        $entities = [EntityType::Category, EntityType::Tag, EntityType::Post, EntityType::Page];
        if ($this->hasWooCommerce) {
            $entities[] = EntityType::Product;
        }

        return $entities;
    }

    public function countEntities(EntityType $type): int
    {
        return match ($type) {
            EntityType::Post => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('posts') . " WHERE post_type = 'post' AND post_status != 'auto-draft'",
            ),
            EntityType::Page => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('posts') . " WHERE post_type = 'page' AND post_status != 'auto-draft'",
            ),
            EntityType::Product => $this->hasWooCommerce ? $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('posts') . " WHERE post_type = 'product'",
            ) : 0,
            EntityType::Category => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('term_taxonomy') . " WHERE taxonomy = 'category'",
            ),
            EntityType::Tag => $this->scalarCount(
                'SELECT COUNT(*) FROM ' . $this->table('term_taxonomy') . " WHERE taxonomy = 'post_tag'",
            ),
            EntityType::Meta => 0,
        };
    }

    public function exportEntities(EntityType $type, int $batchSize): \Generator
    {
        return match ($type) {
            EntityType::Post => $this->exportPosts('post', $batchSize),
            EntityType::Page => $this->exportPosts('page', $batchSize),
            EntityType::Product => $this->exportPosts('product', $batchSize),
            EntityType::Category => $this->exportTaxonomy('category', $batchSize),
            EntityType::Tag => $this->exportTaxonomy('post_tag', $batchSize),
            EntityType::Meta => $this->emptyGenerator(),
        };
    }

    /** @return array<string, string> */
    public function getFieldMap(): array
    {
        return [
            'wordpress.post_title' => 'title',
            'wordpress.post_content' => 'body',
            'wordpress.post_excerpt' => 'excerpt',
            'wordpress.post_name' => 'slug',
            'wordpress.post_status' => 'status',
        ];
    }

    public function importEntities(EntityType $type, array $entities, ImportContextInterface $context): ImportBatchResult
    {
        if ($context->isDryRun()) {
            return $this->dryRunResult($entities);
        }

        return match ($type) {
            EntityType::Post => $this->importPosts($entities, 'post', $context),
            EntityType::Page => $this->importPosts($entities, 'page', $context),
            EntityType::Product => $this->importPosts($entities, 'product', $context),
            EntityType::Category => $this->importTaxonomy($entities, 'category', $context),
            EntityType::Tag => $this->importTaxonomy($entities, 'post_tag', $context),
            EntityType::Meta => new ImportBatchResult(),
        };
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportPosts(string $postType, int $batchSize): \Generator
    {
        $sql = 'SELECT p.ID, p.post_name, p.post_title, p.post_content, p.post_excerpt, p.post_status,
                       p.post_author, p.post_date, p.post_modified, p.post_parent, p.menu_order,
                       u.display_name AS author_name
                FROM ' . $this->table('posts') . ' p
                LEFT JOIN ' . $this->table('users') . ' u ON u.ID = p.post_author
                WHERE p.post_type = :type AND p.post_status != \'auto-draft\'
                ORDER BY p.ID';

        foreach ($this->batchedQuery($sql, $batchSize, ['type' => $postType]) as $row) {
            $id = (int) $row['ID'];
            $entity = match ($postType) {
                'page' => new Page(
                    sourceId: $id,
                    slug: (string) $row['post_name'],
                    title: (string) $row['post_title'],
                    status: $this->mapWpStatus((string) $row['post_status']),
                    body: (string) $row['post_content'],
                    excerpt: (string) $row['post_excerpt'],
                    authorName: $row['author_name'] ?? null,
                    publishedAt: $this->isoDate($row['post_date']),
                    updatedAt: $this->isoDate($row['post_modified']),
                    parentId: (int) $row['post_parent'] ?: null,
                    sortOrder: (int) $row['menu_order'],
                    taxonomyRefs: $this->loadTaxonomyRefs($id),
                    meta: $this->loadPostMeta($id),
                ),
                'product' => new Product(
                    sourceId: $id,
                    slug: (string) $row['post_name'],
                    title: (string) $row['post_title'],
                    status: $this->mapWpStatus((string) $row['post_status']),
                    body: (string) $row['post_content'],
                    excerpt: (string) $row['post_excerpt'],
                    authorName: $row['author_name'] ?? null,
                    publishedAt: $this->isoDate($row['post_date']),
                    updatedAt: $this->isoDate($row['post_modified']),
                    taxonomyRefs: $this->loadTaxonomyRefs($id),
                    meta: $this->loadPostMeta($id),
                ),
                default => new Post(
                    sourceId: $id,
                    slug: (string) $row['post_name'],
                    title: (string) $row['post_title'],
                    status: $this->mapWpStatus((string) $row['post_status']),
                    body: (string) $row['post_content'],
                    excerpt: (string) $row['post_excerpt'],
                    authorName: $row['author_name'] ?? null,
                    publishedAt: $this->isoDate($row['post_date']),
                    updatedAt: $this->isoDate($row['post_modified']),
                    taxonomyRefs: $this->loadTaxonomyRefs($id),
                    meta: $this->loadPostMeta($id),
                ),
            };

            yield $entity->toArray();
        }
    }

    /** @return list<array{type: string, source_id: int|string}> */
    private function loadTaxonomyRefs(int $postId): array
    {
        $sql = 'SELECT tt.taxonomy, t.term_id
                FROM ' . $this->table('term_relationships') . ' tr
                JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                JOIN ' . $this->table('terms') . ' t ON t.term_id = tt.term_id
                WHERE tr.object_id = :id';

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['id' => $postId]);
        $refs = [];
        foreach ($stmt->fetchAll() as $row) {
            $refs[] = ['type' => (string) $row['taxonomy'], 'source_id' => (int) $row['term_id']];
        }

        return $refs;
    }

    /** @return list<array{key: string, value: mixed, type: string}> */
    private function loadPostMeta(int $postId): array
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT meta_key, meta_value FROM ' . $this->table('postmeta') . ' WHERE post_id = :id',
        );
        $stmt->execute(['id' => $postId]);
        $meta = [];
        foreach ($stmt->fetchAll() as $row) {
            if (str_starts_with((string) $row['meta_key'], '_')) {
                continue;
            }
            $meta[] = [
                'key' => (string) $row['meta_key'],
                'value' => $row['meta_value'],
                'type' => is_numeric($row['meta_value']) ? 'int' : 'string',
            ];
        }

        return $meta;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function exportTaxonomy(string $taxonomy, int $batchSize): \Generator
    {
        $sql = 'SELECT t.term_id, t.slug, t.name, tt.description, tt.parent
                FROM ' . $this->table('terms') . ' t
                JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_id = t.term_id
                WHERE tt.taxonomy = :tax
                ORDER BY t.term_id';

        foreach ($this->batchedQuery($sql, $batchSize, ['tax' => $taxonomy]) as $row) {
            $entity = $taxonomy === 'category'
                ? new Category(
                    sourceId: (int) $row['term_id'],
                    slug: (string) $row['slug'],
                    name: (string) $row['name'],
                    type: 'category',
                    description: (string) ($row['description'] ?? ''),
                    parentId: (int) $row['parent'] ?: null,
                )
                : new Tag(
                    sourceId: (int) $row['term_id'],
                    slug: (string) $row['slug'],
                    name: (string) $row['name'],
                    type: 'post_tag',
                    description: (string) ($row['description'] ?? ''),
                );

            yield $entity->toArray();
        }
    }

    /** @param list<array<string, mixed>> $entities */
    private function importPosts(array $entities, string $postType, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $remapper = $context->getIdRemapper();

        foreach ($entities as $entity) {
            $slug = $this->resolveSlug(
                (string) $entity['slug'],
                $context->getDuplicateStrategy(),
                fn (string $s): bool => $this->postSlugExists($s, $postType),
            );

            if ($slug === null) {
                $result = $result->merge(new ImportBatchResult(skipped: 1));
                continue;
            }

            $parentId = null;
            if (!empty($entity['parent_id'])) {
                $parentId = $remapper->resolve(EntityType::Page, $entity['parent_id'])
                    ?? $remapper->resolve(EntityType::Post, $entity['parent_id']);
            }

            $now = date('Y-m-d H:i:s');
            $status = $this->unmapWpStatus((string) $entity['status']);

            if ($context->getDuplicateStrategy() === 'overwrite' && $this->postSlugExists($slug, $postType)) {
                $existingId = $this->findPostIdBySlug($slug, $postType);
                $stmt = $pdo->prepare(
                    'UPDATE ' . $this->table('posts') . ' SET post_title = :title, post_content = :body,
                     post_excerpt = :excerpt, post_status = :status, post_modified = :modified
                     WHERE ID = :id',
                );
                $stmt->execute([
                    'title' => $entity['title'],
                    'body' => $entity['body'] ?? '',
                    'excerpt' => $entity['excerpt'] ?? '',
                    'status' => $status,
                    'modified' => $now,
                    'id' => $existingId,
                ]);
                $targetId = $existingId;
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO ' . $this->table('posts') . ' (post_author, post_date, post_date_gmt, post_content,
                     post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name,
                     to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid,
                     menu_order, post_type, post_mime_type, comment_count)
                     VALUES (1, :date, :date, :body, :title, :excerpt, :status, \'closed\', \'closed\', \'\', :slug,
                     \'\', \'\', :modified, :modified, \'\', :parent, \'\', :sort, :type, \'\', 0)',
                );
                $stmt->execute([
                    'date' => $now,
                    'body' => $entity['body'] ?? '',
                    'title' => $entity['title'],
                    'excerpt' => $entity['excerpt'] ?? '',
                    'status' => $status,
                    'slug' => $slug,
                    'modified' => $now,
                    'parent' => $parentId ?? 0,
                    'sort' => $entity['sort_order'] ?? 0,
                    'type' => $postType,
                ]);
                $targetId = (int) $pdo->lastInsertId();
            }

            $this->assignTaxonomies($targetId, $entity['taxonomy_refs'] ?? [], $context);
            $this->savePostMeta($targetId, $entity['meta'] ?? [], $context->getDuplicateStrategy());

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $targetId],
            ));
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $entities */
    private function importTaxonomy(array $entities, string $taxonomy, ImportContextInterface $context): ImportBatchResult
    {
        $result = new ImportBatchResult();
        $pdo = $this->getPdo();
        $remapper = $context->getIdRemapper();
        $entityType = $taxonomy === 'category' ? EntityType::Category : EntityType::Tag;

        foreach ($entities as $entity) {
            $slug = $this->resolveSlug(
                (string) $entity['slug'],
                $context->getDuplicateStrategy(),
                fn (string $s): bool => $this->termSlugExists($s, $taxonomy),
            );

            if ($slug === null) {
                $result = $result->merge(new ImportBatchResult(skipped: 1));
                continue;
            }

            $parentId = 0;
            if (!empty($entity['parent_id'])) {
                $resolved = $remapper->resolve(EntityType::Category, $entity['parent_id']);
                $parentId = $resolved !== null ? (int) $resolved : 0;
            }

            $termId = $this->findOrCreateTerm($slug, (string) $entity['name'], $taxonomy, $parentId, $context);

            $result = $result->merge(new ImportBatchResult(
                created: 1,
                idMap: [(string) $entity['source_id'] => $termId],
            ));
        }

        return $result;
    }

    private function findOrCreateTerm(
        string $slug,
        string $name,
        string $taxonomy,
        int $parentId,
        ImportContextInterface $context,
    ): int {
        $existing = $this->findTermIdBySlug($slug, $taxonomy);
        if ($existing !== null && $context->getDuplicateStrategy() !== 'overwrite') {
            return $existing;
        }

        $pdo = $this->getPdo();
        if ($existing !== null) {
            $pdo->prepare('UPDATE ' . $this->table('terms') . ' SET name = :name, slug = :slug WHERE term_id = :id')
                ->execute(['name' => $name, 'slug' => $slug, 'id' => $existing]);

            return $existing;
        }

        $pdo->prepare('INSERT INTO ' . $this->table('terms') . ' (name, slug, term_group) VALUES (:name, :slug, 0)')
            ->execute(['name' => $name, 'slug' => $slug]);
        $termId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO ' . $this->table('term_taxonomy') . ' (term_id, taxonomy, description, parent, count)
             VALUES (:term_id, :tax, \'\', :parent, 0)',
        )->execute(['term_id' => $termId, 'tax' => $taxonomy, 'parent' => $parentId]);

        return $termId;
    }

    /** @param list<array{type: string, source_id: int|string}> $refs */
    private function assignTaxonomies(int $postId, array $refs, ImportContextInterface $context): void
    {
        $remapper = $context->getIdRemapper();
        foreach ($refs as $ref) {
            $tax = (string) $ref['type'];
            $type = $tax === 'category' ? EntityType::Category : EntityType::Tag;
            $termId = $remapper->resolve($type, $ref['source_id']);
            if ($termId === null) {
                continue;
            }

            $ttId = $this->getTermTaxonomyId((int) $termId, $tax);
            if ($ttId === null) {
                continue;
            }

            if ($this->termRelationshipExists($postId, $ttId)) {
                continue;
            }

            $this->getPdo()->prepare(
                'INSERT INTO ' . $this->table('term_relationships') . ' (object_id, term_taxonomy_id, term_order)
                 VALUES (:obj, :tt, 0)',
            )->execute(['obj' => $postId, 'tt' => $ttId]);
        }
    }

    private function termRelationshipExists(int $postId, int $termTaxonomyId): bool
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT 1 FROM ' . $this->table('term_relationships') . ' WHERE object_id = :obj AND term_taxonomy_id = :tt LIMIT 1',
        );
        $stmt->execute(['obj' => $postId, 'tt' => $termTaxonomyId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param list<array{key: string, value: mixed, type: string}> $meta */
    private function savePostMeta(int $postId, array $meta, string $strategy): void
    {
        $pdo = $this->getPdo();
        foreach ($meta as $item) {
            $key = (string) $item['key'];
            $value = (string) ($item['value'] ?? '');
            if ($strategy === 'overwrite') {
                $pdo->prepare('DELETE FROM ' . $this->table('postmeta') . ' WHERE post_id = :id AND meta_key = :key')
                    ->execute(['id' => $postId, 'key' => $key]);
            }
            $pdo->prepare(
                'INSERT INTO ' . $this->table('postmeta') . ' (post_id, meta_key, meta_value) VALUES (:id, :key, :val)',
            )->execute(['id' => $postId, 'key' => $key, 'val' => $value]);
        }
    }

    private function postSlugExists(string $slug, string $postType): bool
    {
        return $this->findPostIdBySlug($slug, $postType) !== null;
    }

    private function findPostIdBySlug(string $slug, string $postType): ?int
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT ID FROM ' . $this->table('posts') . ' WHERE post_name = :slug AND post_type = :type LIMIT 1',
        );
        $stmt->execute(['slug' => $slug, 'type' => $postType]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function termSlugExists(string $slug, string $taxonomy): bool
    {
        return $this->findTermIdBySlug($slug, $taxonomy) !== null;
    }

    private function findTermIdBySlug(string $slug, string $taxonomy): ?int
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT t.term_id FROM ' . $this->table('terms') . ' t
             JOIN ' . $this->table('term_taxonomy') . ' tt ON tt.term_id = t.term_id
             WHERE t.slug = :slug AND tt.taxonomy = :tax LIMIT 1',
        );
        $stmt->execute(['slug' => $slug, 'tax' => $taxonomy]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function getTermTaxonomyId(int $termId, string $taxonomy): ?int
    {
        $stmt = $this->getPdo()->prepare(
            'SELECT term_taxonomy_id FROM ' . $this->table('term_taxonomy') . ' WHERE term_id = :id AND taxonomy = :tax',
        );
        $stmt->execute(['id' => $termId, 'tax' => $taxonomy]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    private function mapWpStatus(string $status): string
    {
        return match ($status) {
            'publish' => 'published',
            'draft', 'pending', 'future' => 'draft',
            'private' => 'private',
            'trash' => 'archived',
            default => 'draft',
        };
    }

    private function unmapWpStatus(string $status): string
    {
        return match ($status) {
            'published' => 'publish',
            'private' => 'private',
            'archived' => 'trash',
            default => 'draft',
        };
    }

    /** @return \Generator<int, never> */
    private function emptyGenerator(): \Generator
    {
        if (false) {
            yield;
        }
    }
}
