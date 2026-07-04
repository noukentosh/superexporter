<?php

declare(strict_types=1);

namespace SuperExport\Contracts;

use SuperExport\Universal\EntityType;

/**
 * Contract every CMS adapter must implement.
 *
 * An adapter translates between the CMS-native database schema and the
 * universal (canonical) entity format used by the export storage.
 */
interface CmsAdapterInterface
{
    /** Machine name, e.g. "wordpress", "bitrix". */
    public function getName(): string;

    /**
     * Detect whether this adapter's CMS is installed at the given root path.
     * Should check file markers first, then (optionally) table signatures.
     */
    public function detect(string $rootPath): bool;

    /** CMS version string, if determinable (e.g. "6.5.2"). */
    public function getCmsVersion(): ?string;

    /** Public site URL, if determinable from the CMS config. */
    public function getSiteUrl(): ?string;

    /** Database table prefix used by the installation (e.g. "wp_"). */
    public function getDbPrefix(): ?string;

    /**
     * Entity types this adapter can export/import for the current installation
     * (e.g. products only when a commerce module is present).
     *
     * @return list<EntityType>
     */
    public function getSupportedEntities(): array;

    /**
     * Total number of records of the given type (used for progress/stats).
     */
    public function countEntities(EntityType $type): int;

    /**
     * Read entities of the given type in batches.
     *
     * Each yielded item must be a canonical-format associative array as
     * described by SchemaRegistry.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function exportEntities(EntityType $type, int $batchSize): \Generator;

    /**
     * Default mapping "source field => canonical field" for the manifest
     * (e.g. ["bitrix.NAME" => "title"]).
     *
     * @return array<string, string>
     */
    public function getFieldMap(): array;

    /**
     * Write a batch of canonical entities into the target CMS.
     *
     * @param list<array<string, mixed>> $entities Canonical records.
     * @param ImportContextInterface     $context  Mapping overrides, id remapping, dry-run flag.
     *
     * @return ImportBatchResult Per-batch outcome (created/skipped/errors + new id pairs).
     */
    public function importEntities(EntityType $type, array $entities, ImportContextInterface $context): ImportBatchResult;
}
