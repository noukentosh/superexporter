<?php

declare(strict_types=1);

namespace SuperExport\Contracts;

use SuperExport\Core\IdRemapper;
use SuperExport\Universal\EntityType;

/**
 * Runtime context passed to adapters during import.
 */
interface ImportContextInterface
{
    /** When true, adapters must validate only and write nothing. */
    public function isDryRun(): bool;

    /** Strategy for slug collisions: "skip" | "suffix" | "overwrite". */
    public function getDuplicateStrategy(): string;

    /**
     * User-confirmed mapping overrides: canonical field => target CMS field.
     *
     * @return array<string, string>
     */
    public function getFieldOverrides(EntityType $type): array;

    /** Shared source_id => target_id table for FK/taxonomy/meta resolution. */
    public function getIdRemapper(): IdRemapper;
}
