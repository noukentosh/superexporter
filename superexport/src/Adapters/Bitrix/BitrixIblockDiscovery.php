<?php

declare(strict_types=1);

namespace SuperExport\Adapters\Bitrix;

use PDO;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\EntityType;

/**
 * Discovers Bitrix information blocks and their sections from the database.
 */
final class BitrixIblockDiscovery
{
    /** @var list<string> */
    private const CONTENT_TYPE_IDS = ['news', 'content'];

    /** @var array<int, array{id: int, code: string, name: string, type_id: string}> */
    private array $iblocks = [];

    /** @var array<string, EntityDefinition> */
    private array $definitions = [];

    /** @var list<EntityKey> */
    private array $entityKeys = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix,
    ) {
        $this->discover();
    }

    /** @return list<EntityKey> */
    public function getEntityKeys(): array
    {
        return $this->entityKeys;
    }

    /** @return array<string, EntityDefinition> */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /** @return list<int> */
    public function getCatalogIblockIds(): array
    {
        $ids = [];
        foreach ($this->iblocks as $iblock) {
            if ($iblock['type_id'] === 'catalog') {
                $ids[] = $iblock['id'];
            }
        }

        return $ids;
    }

    /** @return list<int> */
    public function getContentIblockIds(): array
    {
        $ids = [];
        foreach ($this->iblocks as $iblock) {
            if (in_array($iblock['type_id'], self::CONTENT_TYPE_IDS, true)) {
                $ids[] = $iblock['id'];
            }
        }

        return $ids;
    }

    public function resolveIblockId(EntityKey $key): ?int
    {
        if ($key->value === 'products') {
            $catalog = $this->getCatalogIblockIds();

            return $catalog[0] ?? null;
        }

        if ($key->value === 'posts') {
            $content = $this->getContentIblockIds();

            return $content[0] ?? null;
        }

        if (str_starts_with($key->value, 'iblock:')) {
            return (int) substr($key->value, 7);
        }

        return null;
    }

    public function resolveSectionIblockId(EntityKey $key): ?int
    {
        if ($key->value === 'categories') {
            $first = reset($this->iblocks);

            return $first !== false ? $first['id'] : null;
        }

        if (str_starts_with($key->value, 'iblock_section:')) {
            return (int) substr($key->value, 15);
        }

        return null;
    }

    /** @return list<int> */
    public function getIblockIdsForKey(EntityKey $key): array
    {
        if ($key->value === 'products') {
            return $this->getCatalogIblockIds();
        }

        if ($key->value === 'posts') {
            return $this->getContentIblockIds();
        }

        if (str_starts_with($key->value, 'iblock:')) {
            $id = (int) substr($key->value, 7);

            return isset($this->iblocks[$id]) ? [$id] : [];
        }

        return [];
    }

    private function discover(): void
    {
        if (!$this->tableExists('iblock')) {
            return;
        }

        $sql = 'SELECT ID, CODE, NAME, IBLOCK_TYPE_ID FROM ' . $this->table('iblock');
        try {
            $sql .= " WHERE ACTIVE = 'Y'";
        } catch (\Throwable) {
            // SQLite test fixtures may lack ACTIVE column — handled below.
        }

        $rows = $this->fetchIblocks();

        $hasCatalog = false;
        $hasContent = false;

        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $typeId = (string) ($row['IBLOCK_TYPE_ID'] ?? '');
            $name = (string) ($row['NAME'] ?? 'Iblock ' . $id);

            $this->iblocks[$id] = [
                'id' => $id,
                'code' => (string) ($row['CODE'] ?? ''),
                'name' => $name,
                'type_id' => $typeId,
            ];

            if ($typeId === 'catalog') {
                $hasCatalog = true;
                $key = EntityKey::iblock($id);
                $this->addEntity($key, new EntityDefinition(
                    key: $key,
                    kind: EntityDefinition::KIND_CONTENT,
                    label: $name,
                    canonicalKind: EntityDefinition::CANONICAL_PRODUCT,
                    source: ['cms' => 'bitrix', 'iblock_id' => $id, 'iblock_type_id' => $typeId],
                ));
                $this->addSectionEntity($id, $name);
            } elseif (in_array($typeId, self::CONTENT_TYPE_IDS, true)) {
                $hasContent = true;
            } else {
                $key = EntityKey::iblock($id);
                $this->addEntity($key, new EntityDefinition(
                    key: $key,
                    kind: EntityDefinition::KIND_CONTENT,
                    label: $name,
                    canonicalKind: EntityDefinition::CANONICAL_POST,
                    source: ['cms' => 'bitrix', 'iblock_id' => $id, 'iblock_type_id' => $typeId],
                ));
                $this->addSectionEntity($id, $name);
            }
        }

        if ($hasContent) {
            $this->addEntity(
                EntityKey::fromStandard(EntityType::Post),
                EntityDefinition::forStandard(EntityType::Post, 'News / Content'),
            );
        }

        if ($hasCatalog) {
            $this->addEntity(
                EntityKey::fromStandard(EntityType::Product),
                EntityDefinition::forStandard(EntityType::Product, 'Catalog'),
            );
        }

        if ($this->iblocks !== [] && !$hasContent && !$hasCatalog) {
            // Only custom iblocks — already added individually.
        } elseif ($this->iblocks !== []) {
            $this->addEntity(
                EntityKey::fromStandard(EntityType::Category),
                EntityDefinition::forStandard(EntityType::Category, 'Sections'),
            );
        }
    }

    private function addSectionEntity(int $iblockId, string $iblockName): void
    {
        $key = EntityKey::iblockSection($iblockId);
        $this->addEntity($key, new EntityDefinition(
            key: $key,
            kind: EntityDefinition::KIND_TAXONOMY,
            label: $iblockName . ' — Sections',
            canonicalKind: EntityDefinition::CANONICAL_CATEGORY,
            source: ['cms' => 'bitrix', 'iblock_id' => $iblockId],
        ));
    }

    private function addEntity(EntityKey $key, EntityDefinition $definition): void
    {
        if (isset($this->definitions[$key->value])) {
            return;
        }
        $this->definitions[$key->value] = $definition;
        $this->entityKeys[] = $key;
    }

    /** @return list<array<string, mixed>> */
    private function fetchIblocks(): array
    {
        $table = $this->table('iblock');
        try {
            $stmt = $this->pdo->query(
                'SELECT ID, CODE, NAME, IBLOCK_TYPE_ID FROM ' . $table . " WHERE ACTIVE = 'Y'",
            );

            return $stmt->fetchAll();
        } catch (\Throwable) {
            $stmt = $this->pdo->query(
                'SELECT ID, CODE, NAME, IBLOCK_TYPE_ID FROM ' . $table,
            );

            return $stmt->fetchAll();
        }
    }

    private function tableExists(string $name): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ' . $this->table($name) . ' LIMIT 1');
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
