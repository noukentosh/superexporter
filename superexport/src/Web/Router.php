<?php

declare(strict_types=1);

namespace SuperExport\Web;

use SuperExport\Core\Engine;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Web\Controllers\DashboardController;
use SuperExport\Web\Controllers\ExportController;
use SuperExport\Web\Controllers\ImportController;

/**
 * Maps ?action=... to controller methods. Authentication (secret key)
 * must be verified by the bootstrap before dispatch() is called.
 */
final class Router
{
    private readonly View $view;

    public function __construct(private readonly Engine $engine, string $baseUrl)
    {
        $this->view = new View(__DIR__ . DIRECTORY_SEPARATOR . 'Views', $baseUrl);
    }

    public function dispatch(string $action, string $method): void
    {
        try {
            $this->route($action, $method);
        } catch (SuperExportException $e) {
            $this->respond($this->view->render('error', 'Error', ['message' => $e->getMessage()]), 500);
        }
    }

    private function route(string $action, string $method): void
    {
        $dashboard = new DashboardController($this->engine, $this->view);
        $export = new ExportController($this->engine, $this->view);
        $import = new ImportController($this->engine, $this->view);

        match (true) {
            $action === '' || $action === 'dashboard'
                => $this->respond($dashboard->index()),
            $action === 'export'
                => $this->respond($export->form()),
            $action === 'export-run' && $method === 'POST'
                => $export->run($_POST),
            $action === 'import'
                => $this->respond($import->form()),
            $action === 'import-mapping' && $method === 'POST'
                => $this->respond($import->mapping($_POST)),
            $action === 'import-run' && $method === 'POST'
                => $import->run($_POST),
            default
                => $this->respond($this->view->render('error', 'Not found', [
                    'message' => 'Unknown action: ' . $action,
                ]), 404),
        };
    }

    private function respond(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo $html;
    }
}
