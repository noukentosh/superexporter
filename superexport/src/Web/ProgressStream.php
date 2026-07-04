<?php

declare(strict_types=1);

namespace SuperExport\Web;

/**
 * Streams a long-running operation to the browser as incrementally
 * flushed HTML: an auto-scrolling log followed by a final result block.
 */
final class ProgressStream
{
    private bool $started = false;

    public function __construct(private readonly View $view)
    {
    }

    public function start(string $title): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // disable nginx proxy buffering

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        echo $this->view->renderPartial('header', ['title' => $title]);
        echo '<h1>' . View::e($title) . '</h1>';
        echo '<pre class="log" id="log">';
        $this->pad();
        $this->flush();
    }

    public function line(string $message): void
    {
        echo View::e($message) . "\n";
        echo '<script>var l=document.getElementById("log");l.scrollTop=l.scrollHeight;</script>';
        $this->flush();
    }

    /**
     * Close the log and emit the final HTML (report, links) plus footer.
     */
    public function finish(string $resultHtml): void
    {
        echo '</pre>';
        echo $resultHtml;
        echo $this->view->renderPartial('footer');
        $this->flush();
    }

    private function flush(): void
    {
        if (function_exists('flush')) {
            flush();
        }
    }

    /**
     * Some browsers wait for ~1KB before progressive rendering starts.
     */
    private function pad(): void
    {
        echo str_repeat(' ', 1024) . "\n";
    }
}
