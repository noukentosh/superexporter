<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Universal\EntityType;

/**
 * Tracks source_id => target_id pairs created during import, per entity type.
 * Used to resolve parent_id, taxonomy_refs, relations and meta ownership.
 */
final class IdRemapper
{
    /** @var array<string, array<string, string|int>> */
    private array $map = [];

    public function remember(EntityType $type, string|int $sourceId, string|int $targetId): void
    {
        $this->map[$type->value][(string) $sourceId] = $targetId;
    }

    public function resolve(EntityType $type, string|int $sourceId): string|int|null
    {
        return $this->map[$type->value][(string) $sourceId] ?? null;
    }

    public function has(EntityType $type, string|int $sourceId): bool
    {
        return isset($this->map[$type->value][(string) $sourceId]);
    }

    /**
     * @param array<string|int, string|int> $pairs
     */
    public function rememberBatch(EntityType $type, array $pairs): void
    {
        foreach ($pairs as $sourceId => $targetId) {
            $this->remember($type, $sourceId, $targetId);
        }
    }

    /**
     * Snapshot for persisting into import_map.json.
     *
     * @return array<string, array<string, string|int>>
     */
    public function toArray(): array
    {
        return $this->map;
    }

    /**
     * @param array<string, array<string, string|int>> $map
     */
    public static function fromArray(array $map): self
    {
        $remapper = new self();
        $remapper->map = $map;

        return $remapper;
    }

    /**
     * @param array<string, array<string, string|int>> $map
     */
    public function rememberBatchFromMap(array $map): void
    {
        foreach ($map as $typeValue => $pairs) {
            $type = EntityType::tryFrom($typeValue);
            if ($type === null) {
                continue;
            }
            $this->rememberBatch($type, $pairs);
        }
    }
}
