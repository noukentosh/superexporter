<?php

declare(strict_types=1);

namespace SuperExport\Universal\Entity;

use SuperExport\Universal\EntityType;

/**
 * Standalone meta record for bulk-meta chunks.
 *
 * Field set mirrors SchemaRegistry::META_FIELDS.
 */
final class MetaField
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_JSON = 'json';
    public const TYPE_HTML = 'html';

    /**
     * @param int|string $entitySourceId
     * @param string     $type One of the TYPE_* constants.
     */
    public function __construct(
        public readonly EntityType $entityType,
        public readonly int|string $entitySourceId,
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $type = self::TYPE_STRING,
    ) {
    }

    public static function entityType(): EntityType
    {
        return EntityType::Meta;
    }

    /**
     * @return array<string, mixed> Canonical record for chunk storage.
     */
    public function toArray(): array
    {
        return [
            'entity_type'      => $this->entityType->value,
            'entity_source_id' => $this->entitySourceId,
            'key'              => $this->key,
            'value'            => $this->value,
            'type'             => $this->type,
        ];
    }

    /**
     * @param array<string, mixed> $record Canonical record (e.g. from a chunk file).
     */
    public static function fromArray(array $record): self
    {
        return new self(
            entityType: EntityType::from((string) $record['entity_type']),
            entitySourceId: $record['entity_source_id'],
            key: (string) $record['key'],
            value: $record['value'] ?? null,
            type: (string) ($record['type'] ?? self::TYPE_STRING),
        );
    }
}
