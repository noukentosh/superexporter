<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\EntityType;

/**
 * Maps source manifest entity keys to target adapter entity keys for cross-CMS import.
 */
final class EntityMapper
{
    /**
     * Resolve which target entity key should receive records from a source entity.
     *
     * @param array<string, EntityDefinition> $sourceDefinitions
     * @param array<string, string>           $overrides         source key => target key
     */
    public function map(
        EntityKey $sourceKey,
        EntityDefinition $sourceDefinition,
        CmsAdapterInterface $targetAdapter,
        array $sourceDefinitions,
        array $overrides = [],
    ): ?EntityKey {
        if (isset($overrides[$sourceKey->value])) {
            $override = EntityKey::tryParse($overrides[$sourceKey->value]);
            if ($override !== null && $this->targetSupports($targetAdapter, $override)) {
                return $override;
            }
        }

        $supported = $this->indexSupported($targetAdapter);

        if (isset($supported[$sourceKey->value])) {
            return $sourceKey;
        }

        $canonical = $sourceDefinition->canonicalKind;
        $targetDefinitions = $targetAdapter->getEntityDefinitions();

        foreach ($targetDefinitions as $targetKeyValue => $targetDef) {
            if ($targetDef->canonicalKind === $canonical && isset($supported[$targetKeyValue])) {
                return EntityKey::parse($targetKeyValue);
            }
        }

        return match ($canonical) {
            EntityDefinition::CANONICAL_POST => $this->firstOf($supported, ['posts']),
            EntityDefinition::CANONICAL_PAGE => $this->firstOf($supported, ['pages', 'posts']),
            EntityDefinition::CANONICAL_PRODUCT => $this->firstOf($supported, ['products']),
            EntityDefinition::CANONICAL_CATEGORY => $this->firstOf($supported, ['categories']),
            EntityDefinition::CANONICAL_TAG => $this->firstOf($supported, ['tags', 'categories']),
            default => null,
        };
    }

    /**
     * Build default mapping table for the import UI.
     *
     * @param list<EntityKey>                   $sourceKeys
     * @param array<string, EntityDefinition> $sourceDefinitions
     * @return array<string, array{target: ?EntityKey, label: string, canonical_kind: string}>
     */
    public function buildMappingTable(
        array $sourceKeys,
        array $sourceDefinitions,
        CmsAdapterInterface $targetAdapter,
        array $overrides = [],
    ): array {
        $table = [];
        foreach ($sourceKeys as $sourceKey) {
            $definition = $sourceDefinitions[$sourceKey->value]
                ?? EntityDefinition::forStandard($sourceKey->toStandardType() ?? EntityType::Post);
            $target = $this->map($sourceKey, $definition, $targetAdapter, $sourceDefinitions, $overrides);

            $table[$sourceKey->value] = [
                'target' => $target,
                'label' => $definition->label,
                'canonical_kind' => $definition->canonicalKind,
            ];
        }

        return $table;
    }

    /**
     * Import order: taxonomies first, then content entities.
     *
     * @param list<EntityKey> $keys
     * @return list<EntityKey>
     */
    public function sortForImport(array $keys): array
    {
        $taxonomy = [];
        $content = [];
        $other = [];

        foreach ($keys as $key) {
            if ($key->isTaxonomy()) {
                $taxonomy[] = $key;
            } elseif ($key->isContent()) {
                $content[] = $key;
            } else {
                $other[] = $key;
            }
        }

        return array_merge($taxonomy, $content, $other);
    }

    /** @return array<string, EntityKey> */
    private function indexSupported(CmsAdapterInterface $adapter): array
    {
        $index = [];
        foreach ($adapter->getSupportedEntities() as $key) {
            $index[$key->value] = $key;
        }

        return $index;
    }

    private function targetSupports(CmsAdapterInterface $adapter, EntityKey $key): bool
    {
        foreach ($adapter->getSupportedEntities() as $supported) {
            if ($supported->equals($key)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, EntityKey> $supported @param list<string> $candidates */
    private function firstOf(array $supported, array $candidates): ?EntityKey
    {
        foreach ($candidates as $candidate) {
            if (isset($supported[$candidate])) {
                return $supported[$candidate];
            }
        }

        return null;
    }
}
