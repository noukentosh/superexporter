<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Storage\ImportStateManager;
use SuperExport\Storage\JsonChunkReader;
use SuperExport\Storage\ManifestManager;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

/**
 * Orchestrates import: manifest => chunk files => target adapter, in batches.
 * Taxonomies are imported before content so taxonomy_refs can be remapped.
 */
final class ImportPipeline
{
    /** @var callable(string):void */
    private $progressCallback;

    public function __construct(
        private readonly ManifestManager $manifestManager,
        private readonly SchemaRegistry $schemaRegistry,
        private readonly Serializer $serializer,
        private readonly int $batchSize = 500,
        private readonly EntityMapper $entityMapper = new EntityMapper(),
        ?callable $progressCallback = null,
    ) {
        $this->progressCallback = $progressCallback ?? static function (string $message): void {
        };
    }

    /**
     * @return array<string, array{created: int, skipped: int, errors: list<string>}>
     *         Per-entity-key report.
     */
    public function run(
        CmsAdapterInterface $targetAdapter,
        string $storagePath,
        ImportContextInterface $context,
        bool $resume = false,
    ): array {
        $manifest = $this->manifestManager->load($storagePath);
        $reader = new JsonChunkReader($storagePath, $this->serializer);
        $stateManager = new ImportStateManager($this->serializer);

        $state = null;
        if ($resume && $stateManager->exists($storagePath)) {
            $state = $stateManager->load($storagePath);
            $context->getIdRemapper()->rememberBatchFromMap($state['id_map']);
            $this->report('Resuming import from checkpoint.');
        } elseif (!$context->isDryRun()) {
            $state = $stateManager->createInitial($context->getDuplicateStrategy());
            $stateManager->save($storagePath, $state);
        }

        $available = $this->manifestManager->getEntities($manifest);
        $sourceDefinitions = $this->manifestManager->getEntityDefinitions($manifest);
        $importOrder = $this->entityMapper->sortForImport($available);
        $report = [];

        foreach ($importOrder as $sourceKey) {
            $definition = $sourceDefinitions[$sourceKey->value]
                ?? EntityDefinition::forStandard($sourceKey->toStandardType() ?? \SuperExport\Universal\EntityType::Post);

            $targetKey = $this->entityMapper->map(
                $sourceKey,
                $definition,
                $targetAdapter,
                $sourceDefinitions,
                $context->getEntityMappingOverrides(),
            );

            if ($targetKey === null) {
                $this->report(sprintf('Skipping %s: no matching target entity on %s', $sourceKey->value, $targetAdapter->getName()));
                $report[$sourceKey->value] = ['created' => 0, 'skipped' => 0, 'errors' => ['No target entity mapping']];
                continue;
            }

            if ($state !== null && $stateManager->isTypeCompleted($state, $sourceKey)) {
                $this->report(sprintf('Skipping %s: already completed (resume).', $sourceKey->value));
                continue;
            }

            $chunks = $this->manifestManager->getChunks($manifest, $sourceKey);
            $result = $this->importType(
                $targetAdapter,
                $reader,
                $context,
                $sourceKey,
                $targetKey,
                $chunks,
                $storagePath,
                $stateManager,
                $state,
            );

            $report[$sourceKey->value] = [
                'created' => $result->created,
                'skipped' => $result->skipped,
                'errors' => $result->errors,
                'target' => $targetKey->value,
            ];

            if ($state !== null && !$context->isDryRun()) {
                $stateManager->markTypeCompleted($state, $sourceKey);
                $stateManager->syncIdMap($state, $context->getIdRemapper());
                $stateManager->save($storagePath, $state);
            }
        }

        if (!$context->isDryRun()) {
            $stateManager->clear($storagePath);
            $this->report('Import checkpoint cleared.');
        }

        return $report;
    }

    /**
     * @param list<string> $chunks
     * @param array<string, mixed>|null $state
     */
    private function importType(
        CmsAdapterInterface $adapter,
        JsonChunkReader $reader,
        ImportContextInterface $context,
        EntityKey $sourceKey,
        EntityKey $targetKey,
        array $chunks,
        string $storagePath,
        ImportStateManager $stateManager,
        ?array &$state,
    ): ImportBatchResult {
        $mode = $context->isDryRun() ? ' (dry-run)' : '';
        $mapLabel = $sourceKey->value !== $targetKey->value
            ? sprintf(' (%s → %s)', $sourceKey->value, $targetKey->value)
            : '';
        $this->report(sprintf('Importing %s%s%s: %d chunk(s)', $sourceKey->value, $mapLabel, $mode, count($chunks)));

        $total = new ImportBatchResult();

        foreach ($chunks as $chunkFile) {
            if ($state !== null && $stateManager->isChunkCompleted($state, $sourceKey, $chunkFile)) {
                $this->report(sprintf('  skipping chunk %s (resume)', $chunkFile));
                continue;
            }

            $batch = [];
            foreach ($reader->readChunk($sourceKey, $chunkFile) as $record) {
                $validationErrors = $this->schemaRegistry->validate($sourceKey, $record);
                if ($validationErrors !== []) {
                    $total = $total->merge(new ImportBatchResult(skipped: 1, errors: $validationErrors));
                    continue;
                }

                if ($sourceKey->value !== $targetKey->value) {
                    $record = $this->annotateCrossCmsRecord($record, $sourceKey);
                }

                $batch[] = $record;
                if (count($batch) >= $this->batchSize) {
                    $total = $this->flushBatch($adapter, $context, $sourceKey, $targetKey, $batch, $total);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $total = $this->flushBatch($adapter, $context, $sourceKey, $targetKey, $batch, $total);
            }

            if ($state !== null && !$context->isDryRun()) {
                $stateManager->markChunkCompleted($state, $sourceKey, $chunkFile);
                $stateManager->syncIdMap($state, $context->getIdRemapper());
                $stateManager->save($storagePath, $state);
            }
        }

        $this->report(sprintf(
            '  %s done: created=%d skipped=%d errors=%d',
            $sourceKey->value,
            $total->created,
            $total->skipped,
            count($total->errors)
        ));

        return $total;
    }

    /**
     * @param list<array<string, mixed>> $batch
     */
    private function flushBatch(
        CmsAdapterInterface $adapter,
        ImportContextInterface $context,
        EntityKey $sourceKey,
        EntityKey $targetKey,
        array $batch,
        ImportBatchResult $total,
    ): ImportBatchResult {
        $result = $adapter->importEntities($targetKey, $batch, $context);
        $context->getIdRemapper()->rememberBatch($sourceKey, $result->idMap);

        return $total->merge($result);
    }

    /** @param array<string, mixed> $record */
    private function annotateCrossCmsRecord(array $record, EntityKey $sourceKey): array
    {
        $meta = $record['meta'] ?? [];
        $meta[] = ['key' => '_source_entity_key', 'value' => $sourceKey->value, 'type' => 'string'];
        $record['meta'] = $meta;

        return $record;
    }

    private function report(string $message): void
    {
        ($this->progressCallback)($message);
    }
}
