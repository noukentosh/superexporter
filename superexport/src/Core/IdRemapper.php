<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Universal\EntityKey;
use SuperExport\Universal\EntityType;

/**
 * Tracks source_id => target_id pairs created during import, per entity key.
 * Used to resolve parent_id, taxonomy_refs, relations and meta ownership.
 */
final class IdRemapper
{
    /** @var array<string, array<string, string|int>> */
    private array $map = [];

    public function remember(EntityKey $key, string|int $sourceId, string|int $targetId): void
    {
        $this->map[$key->value][(string) $sourceId] = $targetId;
    }

    public function resolve(EntityKey $key, string|int $sourceId): string|int|null
    {
        return $this->map[$key->value][(string) $sourceId] ?? null;
    }

    public function has(EntityKey $key, string|int $sourceId): bool
    {
        return isset($this->map[$key->value][(string) $sourceId]);
    }

    /**
     * @param array<string|int, string|int> $pairs
     */
    public function rememberBatch(EntityKey $key, array $pairs): void
    {
        foreach ($pairs as $sourceId => $targetId) {
            $this->remember($key, $sourceId, $targetId);
        }
    }

    /** Backward-compatible helper for standard entity types. */
    public function rememberType(EntityType $type, string|int $sourceId, string|int $targetId): void
    {
        $this->remember(EntityKey::fromStandard($type), $sourceId, $targetId);
    }

    /** Backward-compatible helper for standard entity types. */
    public function resolveType(EntityType $type, string|int $sourceId): string|int|null
    {
        return $this->resolve(EntityKey::fromStandard($type), $sourceId);
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
        foreach ($map as $keyValue => $pairs) {
            $key = EntityKey::tryParse((string) $keyValue);
            if ($key === null) {
                continue;
            }
            $this->rememberBatch($key, $pairs);
        }
    }
}
