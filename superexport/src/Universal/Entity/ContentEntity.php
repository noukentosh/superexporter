<?php

declare(strict_types=1);

namespace SuperExport\Universal\Entity;

use SuperExport\Universal\EntityType;

/**
 * Base value object for content-like entities (posts, pages, products).
 *
 * Field set mirrors SchemaRegistry::CONTENT_FIELDS; toArray() output is the
 * canonical record format written into chunk files.
 */
abstract class ContentEntity
{
    /**
     * @param int|string      $sourceId
     * @param int|string|null $parentId
     * @param list<array{type: string, source_id: int|string}>          $taxonomyRefs
     * @param list<array{key: string, value: mixed, type: string}>      $meta
     * @param list<array{type: string, target_source_id: int|string}>   $relations
     * @param list<string>                                              $mediaRefs
     */
    final public function __construct(
        public readonly int|string $sourceId,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $status,
        public readonly ?string $body = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $authorName = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $updatedAt = null,
        public readonly int|string|null $parentId = null,
        public readonly ?int $sortOrder = null,
        public readonly array $taxonomyRefs = [],
        public readonly array $meta = [],
        public readonly array $relations = [],
        public readonly array $mediaRefs = [],
    ) {
    }

    abstract public static function entityType(): EntityType;

    /**
     * @return array<string, mixed> Canonical record for chunk storage.
     */
    public function toArray(): array
    {
        return [
            'source_id'     => $this->sourceId,
            'slug'          => $this->slug,
            'title'         => $this->title,
            'body'          => $this->body,
            'excerpt'       => $this->excerpt,
            'status'        => $this->status,
            'author_name'   => $this->authorName,
            'published_at'  => $this->publishedAt,
            'updated_at'    => $this->updatedAt,
            'parent_id'     => $this->parentId,
            'sort_order'    => $this->sortOrder,
            'taxonomy_refs' => $this->taxonomyRefs,
            'meta'          => $this->meta,
            'relations'     => $this->relations,
            'media_refs'    => $this->mediaRefs,
        ];
    }

    /**
     * @param array<string, mixed> $record Canonical record (e.g. from a chunk file).
     */
    public static function fromArray(array $record): static
    {
        return new static(
            sourceId: $record['source_id'],
            slug: (string) $record['slug'],
            title: (string) $record['title'],
            status: (string) $record['status'],
            body: isset($record['body']) ? (string) $record['body'] : null,
            excerpt: isset($record['excerpt']) ? (string) $record['excerpt'] : null,
            authorName: isset($record['author_name']) ? (string) $record['author_name'] : null,
            publishedAt: isset($record['published_at']) ? (string) $record['published_at'] : null,
            updatedAt: isset($record['updated_at']) ? (string) $record['updated_at'] : null,
            parentId: $record['parent_id'] ?? null,
            sortOrder: isset($record['sort_order']) ? (int) $record['sort_order'] : null,
            taxonomyRefs: $record['taxonomy_refs'] ?? [],
            meta: $record['meta'] ?? [],
            relations: $record['relations'] ?? [],
            mediaRefs: $record['media_refs'] ?? [],
        );
    }
}
