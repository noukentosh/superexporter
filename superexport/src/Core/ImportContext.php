<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityKey;

final class ImportContext implements ImportContextInterface
{
    public const DUPLICATE_STRATEGIES = ['skip', 'suffix', 'overwrite'];

    /**
     * @param array<string, array<string, string>> $fieldOverrides entity key => [canonical => target field]
     * @param array<string, string>                $entityMappingOverrides source key => target key
     */
    public function __construct(
        private readonly IdRemapper $idRemapper,
        private readonly bool $dryRun = false,
        private readonly string $duplicateStrategy = 'skip',
        private readonly array $fieldOverrides = [],
        private readonly array $entityMappingOverrides = [],
    ) {
        if (!in_array($duplicateStrategy, self::DUPLICATE_STRATEGIES, true)) {
            throw new SuperExportException(
                'Unknown duplicate strategy "' . $duplicateStrategy . '". Allowed: '
                . implode(', ', self::DUPLICATE_STRATEGIES)
            );
        }
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getDuplicateStrategy(): string
    {
        return $this->duplicateStrategy;
    }

    public function getFieldOverrides(EntityKey $key): array
    {
        return $this->fieldOverrides[$key->value] ?? [];
    }

    public function getEntityMappingOverrides(): array
    {
        return $this->entityMappingOverrides;
    }

    public function getIdRemapper(): IdRemapper
    {
        return $this->idRemapper;
    }
}
