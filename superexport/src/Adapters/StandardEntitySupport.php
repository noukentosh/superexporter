<?php

declare(strict_types=1);

namespace SuperExport\Adapters;

use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\EntityType;

/**
 * Helpers for adapters that only expose standard EntityType keys.
 */
trait StandardEntitySupport
{
    /**
     * @param list<EntityType> $types
     * @return list<EntityKey>
     */
    protected function keysFromTypes(array $types): array
    {
        return array_map(static fn (EntityType $t): EntityKey => EntityKey::fromStandard($t), $types);
    }

    /**
     * @param list<EntityType> $types
     * @return array<string, EntityDefinition>
     */
    protected function definitionsFromTypes(array $types): array
    {
        $definitions = [];
        foreach ($types as $type) {
            $key = EntityKey::fromStandard($type);
            $definitions[$key->value] = EntityDefinition::forStandard($type);
        }

        return $definitions;
    }

    protected function toStandardType(EntityKey $key): ?EntityType
    {
        return $key->toStandardType();
    }
}
