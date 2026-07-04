<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Storage\ManifestManager;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

/**
 * Facade wiring configuration, detection and pipelines together.
 * Entry points (CLI/Web) talk only to this class.
 */
final class Engine
{
    private readonly Serializer $serializer;
    private readonly SchemaRegistry $schemaRegistry;
    private readonly ManifestManager $manifestManager;
    private readonly CmsDetector $detector;

    /** @var callable(string):void */
    private $progressCallback;

    /**
     * @param array<string, mixed> $config Parsed config.php contents.
     */
    public function __construct(
        private readonly array $config,
        ?callable $progressCallback = null,
    ) {
        $this->serializer = new Serializer();
        $this->schemaRegistry = new SchemaRegistry();
        $this->manifestManager = new ManifestManager($this->serializer, $this->schemaRegistry);
        $this->detector = new CmsDetector();
        $this->progressCallback = $progressCallback ?? static function (string $message): void {
        };
    }

    /**
     * Replace the progress listener (used by the Web UI to stream progress).
     *
     * @param callable(string):void $callback
     */
    public function setProgressCallback(callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    public function registerAdapter(CmsAdapterInterface $adapter): void
    {
        $this->detector->register($adapter);
    }

    public function getDetector(): CmsDetector
    {
        return $this->detector;
    }

    public function getManifestManager(): ManifestManager
    {
        return $this->manifestManager;
    }

    /**
     * Detect the CMS installed at the configured (or given) root path.
     */
    public function detectCms(?string $rootPath = null): CmsAdapterInterface
    {
        return $this->detector->detect($rootPath ?? $this->getRootPath());
    }

    /**
     * @return list<array{
     *     name: string,
     *     label: string,
     *     detected: bool,
     *     selected: bool,
     *     checks: list<array{label: string, passed: bool, level: int, detail?: string}>
     * }>
     */
    public function scanCms(?string $rootPath = null): array
    {
        return $this->detector->scanAll($rootPath ?? $this->getRootPath());
    }

    /**
     * @return array{manifest_path: string, stats: array<string, int>}
     */
    public function export(?string $outputPath = null): array
    {
        $adapter = $this->detectCms();
        $pipeline = new ExportPipeline(
            $this->manifestManager,
            $this->serializer,
            $this->getBatchSize(),
            $this->progressCallback,
        );

        return $pipeline->run($adapter, $outputPath ?? $this->getStoragePath());
    }

    /**
     * @param array<string, array<string, string>> $fieldOverrides
     * @return array<string, array{created: int, skipped: int, errors: list<string>}>
     */
    public function import(
        string $inputPath,
        bool $dryRun = false,
        string $duplicateStrategy = 'skip',
        array $fieldOverrides = [],
        bool $resume = false,
        array $entityMappingOverrides = [],
    ): array {
        $adapter = $this->detectCms();
        $context = new ImportContext(
            new IdRemapper(),
            $dryRun,
            $duplicateStrategy,
            $fieldOverrides,
            $entityMappingOverrides,
        );

        $pipeline = new ImportPipeline(
            $this->manifestManager,
            $this->schemaRegistry,
            $this->serializer,
            $this->getBatchSize(),
            $this->progressCallback,
        );

        $report = $pipeline->run($adapter, $inputPath, $context, $resume);

        if (!$dryRun) {
            $this->saveImportMap($inputPath, $fieldOverrides, $context->getIdRemapper());
        }

        return $report;
    }

    public function getSecretKey(): string
    {
        $key = (string) ($this->config['secret_key'] ?? '');
        if ($key === '') {
            throw new SuperExportException('secret_key is not configured in config.php.');
        }

        return $key;
    }

    public function getRootPath(): string
    {
        return (string) ($this->config['cms_root'] ?? getcwd());
    }

    public function getStoragePath(): string
    {
        return (string) ($this->config['storage_path'] ?? $this->getRootPath() . DIRECTORY_SEPARATOR . 'superexport' . DIRECTORY_SEPARATOR . 'storage');
    }

    public function getBatchSize(): int
    {
        return max(1, (int) ($this->config['batch_size'] ?? 500));
    }

    /**
     * @param array<string, array<string, string>> $fieldOverrides
     */
    private function saveImportMap(string $inputPath, array $fieldOverrides, IdRemapper $remapper): void
    {
        $path = $inputPath . DIRECTORY_SEPARATOR . 'import_map.json';
        $payload = $this->serializer->encode([
            'field_overrides' => $fieldOverrides,
            'id_map' => $remapper->toArray(),
        ]);

        if (@file_put_contents($path, $payload) === false) {
            throw new SuperExportException('Cannot write import map: ' . $path);
        }
    }
}
