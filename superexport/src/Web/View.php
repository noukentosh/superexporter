<?php

declare(strict_types=1);

namespace SuperExport\Web;

use SuperExport\Exceptions\SuperExportException;

/**
 * Minimal PHP-template renderer. Templates live in Views/ and receive
 * extracted variables plus `$view` (this instance) for URL building/escaping.
 */
final class View
{
    public function __construct(
        private readonly string $viewsPath,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Build a UI URL for an action, keeping the secret key.
     *
     * @param array<string, string> $params
     */
    public function url(string $action, array $params = []): string
    {
        return $this->baseUrl . '&' . http_build_query(['action' => $action] + $params);
    }

    /**
     * Render a template inside the shared layout.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $template, string $title, array $vars = []): string
    {
        $content = $this->renderPartial($template, $vars);

        return $this->renderPartial('layout', [
            'title' => $title,
            'content' => $content,
        ]);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function renderPartial(string $template, array $vars = []): string
    {
        $file = $this->viewsPath . DIRECTORY_SEPARATOR . $template . '.php';
        if (!is_file($file)) {
            throw new SuperExportException('View template not found: ' . $template);
        }

        $vars['view'] = $this;
        extract($vars, EXTR_SKIP);

        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
