<?php

declare(strict_types=1);

namespace SuperExport\Web\Controllers;

use SuperExport\Core\Engine;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Storage\JsonChunkReader;
use SuperExport\Universal\EntityType;
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
     * Step 2: mapping wizard — canonical field -> target CMS field,
     * pre-filled with the target adapter defaults.
     *
     * @param array<string, mixed> $post
     */
    public function mapping(array $post): string
    {
        $input = $this->requireInputPath($post);
        $manifest = $this->engine->getManifestManager()->load($input);
        $adapter = $this->engine->detectCms();

        $available = $this->engine->getManifestManager()->getEntities($manifest);
        $supported = $adapter->getSupportedEntities();

        $types = array_values(array_filter(
            $available,
            static fn (EntityType $t): bool => in_array($t, $supported, true)
        ));
        $unsupported = array_values(array_filter(
            $available,
            static fn (EntityType $t): bool => !in_array($t, $supported, true)
        ));

        // Adapter map is "native field => canonical"; invert it to suggest
        // a native target field for every canonical field.
        $defaults = [];
        foreach ($adapter->getFieldMap() as $native => $canonical) {
            $defaults[$canonical] ??= $native;
        }

        /** @var array<string, array<string, array{type: string, required: bool}>> $schemaFields */
        $schemaFields = (array) ($manifest['schema']['fields'] ?? []);

        return $this->view->render('import_mapping', 'Import — step 2 of 3', [
            'input' => $input,
            'manifest' => $manifest,
            'targetCms' => $adapter->getName(),
            'types' => $types,
            'unsupported' => $unsupported,
            'schemaFields' => $schemaFields,
            'defaults' => $defaults,
            'preview' => $this->buildPreview($input, $manifest, $types),
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

        $stream = new ProgressStream($this->view);
        $stream->start($dryRun ? 'Import — dry-run' : 'Import — running');

        $this->engine->setProgressCallback(
            static fn (string $message) => $stream->line($message)
        );

        try {
            $report = $this->engine->import($input, $dryRun, $duplicates, $overrides, $resume);
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
     * Extract non-empty mapping overrides: map[<entity>][<canonical>] = target field.
     *
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
            if (EntityType::tryFrom((string) $typeName) === null || !is_array($fields)) {
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
     * First records of every importable type for the mapping screen.
     *
     * @param array<string, mixed> $manifest
     * @param list<EntityType> $types
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildPreview(string $input, array $manifest, array $types): array
    {
        $reader = new JsonChunkReader($input, new Serializer());
        $preview = [];

        foreach ($types as $type) {
            $chunks = $this->engine->getManifestManager()->getChunks($manifest, $type);
            if ($chunks === []) {
                continue;
            }

            try {
                $records = $reader->readChunk($type, $chunks[0]);
            } catch (SuperExportException) {
                continue;
            }

            $preview[$type->value] = array_slice($records, 0, self::PREVIEW_RECORDS);
        }

        return $preview;
    }
}
