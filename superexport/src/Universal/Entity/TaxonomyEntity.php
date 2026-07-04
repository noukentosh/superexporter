<?php

declare(strict_types=1);

namespace SuperExport\Universal\Entity;

use SuperExport\Universal\EntityType;

/**
 * Base value object for taxonomy entities (categories, tags).
 *
 * Field set mirrors SchemaRegistry::TAXONOMY_FIELDS.
 */
abstract class TaxonomyEntity
{
    /**
     * @param int|string      $sourceId
     * @param int|string|null $parentId
     * @param string          $type CMS-specific taxonomy type (e.g. "category", "post_tag").
     */
    final public function __construct(
        public readonly int|string $sourceId,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $description = null,
        public readonly int|string|null $parentId = null,
    ) {
    }

    abstract public static function entityType(): EntityType;

    /**
     * @return array<string, mixed> Canonical record for chunk storage.
     */
    public function toArray(): array
    {
        return [
            'source_id'   => $this->sourceId,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'parent_id'   => $this->parentId,
            'type'        => $this->type,
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
            name: (string) $record['name'],
            type: (string) $record['type'],
            description: isset($record['description']) ? (string) $record['description'] : null,
            parentId: $record['parent_id'] ?? null,
        );
    }
}
