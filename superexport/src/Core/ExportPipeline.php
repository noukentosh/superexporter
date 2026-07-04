<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Storage\JsonChunkWriter;
use SuperExport\Storage\ManifestManager;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\Serializer;

/**
 * Orchestrates a full export: adapter => chunk files => manifest.json.
 */
final class ExportPipeline
{
    /** @var callable(string):void */
    private $progressCallback;

    public function __construct(
        private readonly ManifestManager $manifestManager,
        private readonly Serializer $serializer,
        private readonly int $batchSize = 500,
        ?callable $progressCallback = null,
    ) {
        $this->progressCallback = $progressCallback ?? static function (string $message): void {
        };
    }

    /**
     * @return array{manifest_path: string, stats: array<string, int>}
     */
    public function run(CmsAdapterInterface $adapter, string $storagePath): array
    {
        $this->prepareStorageDir($storagePath);

        $writer = new JsonChunkWriter($storagePath, $this->serializer, $this->batchSize);
        $entities = $adapter->getSupportedEntities();
        $definitions = $adapter->getEntityDefinitions();
        $stats = [];

        foreach ($entities as $key) {
            $total = $adapter->countEntities($key);
            $stats[$key->value] = $total;
            $this->report(sprintf('Exporting %s: %d records', $key->value, $total));

            $done = 0;
            foreach ($adapter->exportEntities($key, $this->batchSize) as $record) {
                $writer->write($key, $record);
                $done++;
                if ($done % $this->batchSize === 0) {
                    $this->report(sprintf('  %s: %d/%d', $key->value, $done, $total));
                }
            }
        }

        $chunks = $writer->finalize();

        $manifest = $this->manifestManager->build(
            source: [
                'cms' => $adapter->getName(),
                'cms_version' => $adapter->getCmsVersion(),
                'site_url' => $adapter->getSiteUrl(),
                'db_prefix' => $adapter->getDbPrefix(),
            ],
            entities: $entities,
            stats: $stats,
            sourceFieldMap: $adapter->getFieldMap(),
            chunks: $chunks,
            entityDefinitions: $definitions,
        );

        $manifestPath = $this->manifestManager->save($storagePath, $manifest);
        $this->report('Export finished: ' . $manifestPath);

        return ['manifest_path' => $manifestPath, 'stats' => $stats];
    }

    private function prepareStorageDir(string $storagePath): void
    {
        if (!is_dir($storagePath) && !@mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
            throw new SuperExportException('Cannot create storage directory: ' . $storagePath);
        }

        if (!is_writable($storagePath)) {
            throw new SuperExportException('Storage directory is not writable: ' . $storagePath);
        }
    }

    private function report(string $message): void
    {
        ($this->progressCallback)($message);
    }
}
