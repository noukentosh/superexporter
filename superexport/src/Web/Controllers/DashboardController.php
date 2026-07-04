<?php

declare(strict_types=1);

namespace SuperExport\Web\Controllers;

use SuperExport\Core\Engine;
use SuperExport\Exceptions\CmsNotDetectedException;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityType;
use SuperExport\Web\View;

/**
 * Landing page: detected CMS, storage status, last export stats.
 */
final class DashboardController
{
    public function __construct(
        private readonly Engine $engine,
        private readonly View $view,
    ) {
    }

    public function index(): string
    {
        $cms = null;
        $cmsError = null;

        try {
            $adapter = $this->engine->detectCms();
            $cms = [
                'name' => $adapter->getName(),
                'version' => $adapter->getCmsVersion() ?? 'unknown',
                'site_url' => $adapter->getSiteUrl() ?? 'unknown',
                'db_prefix' => $adapter->getDbPrefix() ?? 'unknown',
                'entities' => array_map(
                    static fn (EntityType $t): string => $t->value,
                    $adapter->getSupportedEntities()
                ),
            ];
        } catch (CmsNotDetectedException $e) {
            $cmsError = $e->getMessage();
        }

        return $this->view->render('dashboard', 'Dashboard', [
            'cms' => $cms,
            'cmsError' => $cmsError,
            'cmsRoot' => $this->engine->getRootPath(),
            'detectionScan' => $this->engine->scanCms(),
            'storagePath' => $this->engine->getStoragePath(),
            'lastExport' => $this->loadLastExport(),
        ]);
    }

    /**
     * @return array{exported_at: string, cms: string, stats: array<string, int>}|null
     */
    private function loadLastExport(): ?array
    {
        try {
            $manifest = $this->engine->getManifestManager()->load($this->engine->getStoragePath());
        } catch (SuperExportException) {
            return null;
        }

        return [
            'exported_at' => (string) ($manifest['exported_at'] ?? ''),
            'cms' => (string) ($manifest['source']['cms'] ?? 'unknown'),
            'stats' => array_map('intval', (array) ($manifest['stats'] ?? [])),
        ];
    }
}
