<?php
/**
 * Base Controller
 * All controllers extend this class.
 */
abstract class Controller
{
    /**
     * Render a view file, optionally inside a layout.
     *
     * @param string $view   Relative path under app/views/, e.g. 'auth/login'
     * @param array  $data   Variables to extract into the view
     * @param string|null $layout Layout name under app/views/layouts/, or null for no layout
     */
    protected function render(string $view, array $data = [], ?string $layout = 'main'): void
    {
        // Make data variables available in view scope
        extract($data, EXTR_SKIP);

        $viewFile = VIEW_PATH . '/' . $view . '.php';

        if (!file_exists($viewFile)) {
            http_response_code(404);
            die("View not found: {$viewFile}");
        }

        if ($layout !== null) {
            $layoutFile = VIEW_PATH . '/layouts/' . $layout . '.php';
            if (!file_exists($layoutFile)) {
                die("Layout not found: {$layoutFile}");
            }
            // Capture the view content
            ob_start();
            include $viewFile;
            $content = ob_get_clean();

            include $layoutFile;
        } else {
            include $viewFile;
        }
    }

    /**
     * Render a view file to a string instead of the output buffer -- for
     * building content that isn't an HTTP response body, e.g. an email
     * body (Member Statement Emailing stage). Never wraps in a layout;
     * the view itself must already be a complete document.
     */
    protected function renderToString(string $view, array $data = []): string
    {
        extract($data, EXTR_SKIP);

        $viewFile = VIEW_PATH . '/' . $view . '.php';
        if (!file_exists($viewFile)) {
            throw new RuntimeException("View not found: {$viewFile}");
        }

        ob_start();
        include $viewFile;
        return ob_get_clean();
    }

    /**
     * Redirect to a URL.
     *
     * @param string $url  Full URL or relative path
     */
    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Return a JSON response and exit.
     *
     * @param array $data   Data to encode
     * @param int   $status HTTP status code
     */
    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Check if the current request is POST.
     */
    protected function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Sanitize a string input.
     */
    protected function sanitize(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}
