<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityType;

final class ImportContext implements ImportContextInterface
{
    public const DUPLICATE_STRATEGIES = ['skip', 'suffix', 'overwrite'];

    /**
     * @param array<string, array<string, string>> $fieldOverrides entity type => [canonical => target field]
     */
    public function __construct(
        private readonly IdRemapper $idRemapper,
        private readonly bool $dryRun = false,
        private readonly string $duplicateStrategy = 'skip',
        private readonly array $fieldOverrides = [],
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

    public function getFieldOverrides(EntityType $type): array
    {
        return $this->fieldOverrides[$type->value] ?? [];
    }

    public function getIdRemapper(): IdRemapper
    {
        return $this->idRemapper;
    }
}
