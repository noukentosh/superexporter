<?php

declare(strict_types=1);

namespace SuperExport\Contracts;

use SuperExport\Core\IdRemapper;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;

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

    /**
     * Diagnostic breakdown of detection steps (files, DB, tables).
     *
     * @return array{
     *     detected: bool,
     *     checks: list<array{label: string, passed: bool, level: int, detail?: string}>
     * }
     */
    public function probeDetection(string $rootPath): array;

    /** CMS version string, if determinable (e.g. "6.5.2"). */
    public function getCmsVersion(): ?string;

    /** Public site URL, if determinable from the CMS config. */
    public function getSiteUrl(): ?string;

    /** Database table prefix used by the installation (e.g. "wp_"). */
    public function getDbPrefix(): ?string;

    /**
     * Entity keys this adapter can export/import for the current installation.
     *
     * @return list<EntityKey>
     */
    public function getSupportedEntities(): array;

    /**
     * Metadata for each supported entity (labels, canonical kind, native source info).
     *
     * @return array<string, EntityDefinition> keyed by EntityKey->value
     */
    public function getEntityDefinitions(): array;

    /**
     * Total number of records of the given key (used for progress/stats).
     */
    public function countEntities(EntityKey $key): int;

    /**
     * Read entities of the given key in batches.
     *
     * Each yielded item must be a canonical-format associative array as
     * described by SchemaRegistry.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function exportEntities(EntityKey $key, int $batchSize): \Generator;

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
    public function importEntities(EntityKey $key, array $entities, ImportContextInterface $context): ImportBatchResult;
}
