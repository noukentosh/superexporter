<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Storage\JsonChunkReader;
use SuperExport\Storage\ManifestManager;
use SuperExport\Universal\EntityType;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

/**
 * Orchestrates import: manifest => chunk files => target adapter, in batches.
 * Taxonomies are imported before content so taxonomy_refs can be remapped.
 */
final class ImportPipeline
{
    private const IMPORT_ORDER = [
        EntityType::Category,
        EntityType::Tag,
        EntityType::Post,
        EntityType::Page,
        EntityType::Product,
        EntityType::Meta,
    ];

    /** @var callable(string):void */
    private $progressCallback;

    public function __construct(
        private readonly ManifestManager $manifestManager,
        private readonly SchemaRegistry $schemaRegistry,
        private readonly Serializer $serializer,
        private readonly int $batchSize = 500,
        ?callable $progressCallback = null,
    ) {
        $this->progressCallback = $progressCallback ?? static function (string $message): void {
        };
    }

    /**
     * @return array<string, array{created: int, skipped: int, errors: list<string>}>
     *         Per-entity-type report.
     */
    public function run(
        CmsAdapterInterface $targetAdapter,
        string $storagePath,
        ImportContextInterface $context,
    ): array {
        $manifest = $this->manifestManager->load($storagePath);
        $reader = new JsonChunkReader($storagePath, $this->serializer);

        $available = $this->manifestManager->getEntities($manifest);
        $supported = $targetAdapter->getSupportedEntities();
        $report = [];

        foreach (self::IMPORT_ORDER as $type) {
            if (!in_array($type, $available, true)) {
                continue;
            }
            if (!in_array($type, $supported, true)) {
                $this->report(sprintf('Skipping %s: not supported by %s', $type->value, $targetAdapter->getName()));
                continue;
            }

            $chunks = $this->manifestManager->getChunks($manifest, $type);
            $result = $this->importType($targetAdapter, $reader, $context, $type, $chunks);

            $report[$type->value] = [
                'created' => $result->created,
                'skipped' => $result->skipped,
                'errors' => $result->errors,
            ];
        }

        return $report;
    }

    /**
     * @param list<string> $chunks
     */
    private function importType(
        CmsAdapterInterface $adapter,
        JsonChunkReader $reader,
        ImportContextInterface $context,
        EntityType $type,
        array $chunks,
    ): ImportBatchResult {
        $mode = $context->isDryRun() ? ' (dry-run)' : '';
        $this->report(sprintf('Importing %s%s: %d chunk(s)', $type->value, $mode, count($chunks)));

        $total = new ImportBatchResult();
        $batch = [];

        foreach ($reader->read($type, $chunks) as $record) {
            $validationErrors = $this->schemaRegistry->validate($type, $record);
            if ($validationErrors !== []) {
                $total = $total->merge(new ImportBatchResult(skipped: 1, errors: $validationErrors));
                continue;
            }

            $batch[] = $record;
            if (count($batch) >= $this->batchSize) {
                $total = $this->flushBatch($adapter, $context, $type, $batch, $total);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $total = $this->flushBatch($adapter, $context, $type, $batch, $total);
        }

        $this->report(sprintf(
            '  %s done: created=%d skipped=%d errors=%d',
            $type->value,
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
        EntityType $type,
        array $batch,
        ImportBatchResult $total,
    ): ImportBatchResult {
        $result = $adapter->importEntities($type, $batch, $context);
        $context->getIdRemapper()->rememberBatch($type, $result->idMap);

        return $total->merge($result);
    }

    private function report(string $message): void
    {
        ($this->progressCallback)($message);
    }
}
