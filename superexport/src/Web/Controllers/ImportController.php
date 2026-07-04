<?php

declare(strict_types=1);

namespace SuperExport\Web\Controllers;

use SuperExport\Core\Engine;
use SuperExport\Core\EntityMapper;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Storage\JsonChunkReader;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\Serializer;
use SuperExport\Web\ProgressStream;
use SuperExport\Web\View;

/**
 * Import wizard: storage selection -> field mapping -> streamed run
 * (dry-run or real import).
 */
final class ImportController
{
    private const PREVIEW_RECORDS = 3;

    public function __construct(
        private readonly Engine $engine,
        private readonly View $view,
    ) {
    }

    /** Step 1: choose the storage directory to import from. */
    public function form(): string
    {
        return $this->view->render('import_form', 'Import — step 1 of 3', [
            'defaultInput' => $this->engine->getStoragePath(),
        ]);
    }

    /**
     * Step 2: mapping wizard — entity mapping + canonical field -> target CMS field.
     *
     * @param array<string, mixed> $post
     */
    public function mapping(array $post): string
    {
        $input = $this->requireInputPath($post);
        $manifest = $this->engine->getManifestManager()->load($input);
        $adapter = $this->engine->detectCms();
        $mapper = new EntityMapper();

        $available = $this->engine->getManifestManager()->getEntities($manifest);
        $sourceDefinitions = $this->engine->getManifestManager()->getEntityDefinitions($manifest);
        $entityMapping = $mapper->buildMappingTable($available, $sourceDefinitions, $adapter);

        $importable = [];
        $unsupported = [];
        foreach ($entityMapping as $sourceKey => $info) {
            if ($info['target'] !== null) {
                $importable[] = EntityKey::parse($sourceKey);
            } else {
                $unsupported[] = EntityKey::parse($sourceKey);
            }
        }

        $defaults = [];
        foreach ($adapter->getFieldMap() as $native => $canonical) {
            $defaults[$canonical] ??= $native;
        }

        /** @var array<string, array<string, array{type: string, required: bool}>> $schemaFields */
        $schemaFields = (array) ($manifest['schema']['fields'] ?? []);

        $supportedTargets = [];
        foreach ($adapter->getSupportedEntities() as $targetKey) {
            $supportedTargets[$targetKey->value] = $targetKey->value;
        }

        return $this->view->render('import_mapping', 'Import — step 2 of 3', [
            'input' => $input,
            'manifest' => $manifest,
            'targetCms' => $adapter->getName(),
            'types' => $importable,
            'unsupported' => $unsupported,
            'entityMapping' => $entityMapping,
            'supportedTargets' => $supportedTargets,
            'schemaFields' => $schemaFields,
            'defaults' => $defaults,
            'preview' => $this->buildPreview($input, $manifest, $importable),
        ]);
    }

    /**
     * Step 3: run the import (or dry-run) with streamed progress.
     *
     * @param array<string, mixed> $post
     */
    public function run(array $post): void
    {
        $input = $this->requireInputPath($post);
        $dryRun = !empty($post['dry_run']);
        $resume = !empty($post['resume']) && !$dryRun;
        $duplicates = (string) ($post['duplicates'] ?? 'skip');
        $overrides = $this->collectOverrides($post);
        $entityMapping = $this->collectEntityMapping($post);

        $stream = new ProgressStream($this->view);
        $stream->start($dryRun ? 'Import — dry-run' : 'Import — running');

        $this->engine->setProgressCallback(
            static fn (string $message) => $stream->line($message)
        );

        try {
            $report = $this->engine->import($input, $dryRun, $duplicates, $overrides, $resume, $entityMapping);
        } catch (SuperExportException $e) {
            $stream->line('ERROR: ' . $e->getMessage());
            $stream->finish($this->view->renderPartial('operation_failed', [
                'message' => $e->getMessage(),
            ]));

            return;
        }

        $stream->line($dryRun ? 'Dry-run finished (nothing was written).' : 'Import finished.');
        $stream->finish($this->view->renderPartial('import_result', [
            'report' => $report,
            'dryRun' => $dryRun,
            'input' => $input,
            'overrides' => $overrides,
            'duplicates' => $duplicates,
        ]));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function requireInputPath(array $post): string
    {
        $input = trim((string) ($post['input'] ?? ''));
        if ($input === '') {
            throw new SuperExportException('Storage directory is required.');
        }
        if (!is_dir($input)) {
            throw new SuperExportException('Storage directory not found: ' . $input);
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, array<string, string>>
     */
    private function collectOverrides(array $post): array
    {
        $overrides = [];
        $map = $post['map'] ?? [];
        if (!is_array($map)) {
            return [];
        }

        foreach ($map as $typeName => $fields) {
            if (EntityKey::tryParse((string) $typeName) === null || !is_array($fields)) {
                continue;
            }
            foreach ($fields as $canonical => $target) {
                $target = trim((string) $target);
                if ($target !== '') {
                    $overrides[(string) $typeName][(string) $canonical] = $target;
                }
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function collectEntityMapping(array $post): array
    {
        $mapping = [];
        $raw = $post['entity_map'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $source => $target) {
            $source = trim((string) $source);
            $target = trim((string) $target);
            if ($source !== '' && $target !== '') {
                $mapping[$source] = $target;
            }
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<EntityKey> $types
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildPreview(string $input, array $manifest, array $types): array
    {
        $reader = new JsonChunkReader($input, new Serializer());
        $preview = [];

        foreach ($types as $key) {
            $chunks = $this->engine->getManifestManager()->getChunks($manifest, $key);
            if ($chunks === []) {
                continue;
            }

            try {
                $records = $reader->readChunk($key, $chunks[0]);
            } catch (SuperExportException) {
                continue;
            }

            $preview[$key->value] = array_slice($records, 0, self::PREVIEW_RECORDS);
        }

        return $preview;
    }
}
