<?php

declare(strict_types=1);

namespace SuperExport\Web\Controllers;

use SuperExport\Core\Engine;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Web\ProgressStream;
use SuperExport\Web\View;

/**
 * Export wizard: options form + streamed export run.
 */
final class ExportController
{
    public function __construct(
        private readonly Engine $engine,
        private readonly View $view,
    ) {
    }

    public function form(): string
    {
        return $this->view->render('export_form', 'Export', [
            'defaultOutput' => $this->engine->getStoragePath(),
        ]);
    }

    /**
     * Streams progress while the export runs (no background job needed).
     *
     * @param array<string, mixed> $post
     */
    public function run(array $post): void
    {
        $output = trim((string) ($post['output'] ?? ''));

        $stream = new ProgressStream($this->view);
        $stream->start('Export — running');

        $this->engine->setProgressCallback(
            static fn (string $message) => $stream->line($message)
        );

        try {
            $result = $this->engine->export($output !== '' ? $output : null);
        } catch (SuperExportException $e) {
            $stream->line('ERROR: ' . $e->getMessage());
            $stream->finish($this->view->renderPartial('operation_failed', [
                'message' => $e->getMessage(),
            ]));

            return;
        }

        $stream->line('Export finished.');
        $stream->finish($this->view->renderPartial('export_result', [
            'manifestPath' => $result['manifest_path'],
            'stats' => $result['stats'],
        ]));
    }
}
