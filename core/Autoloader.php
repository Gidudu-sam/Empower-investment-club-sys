<?php
/**
 * PSR-4-style autoloader for the application.
 * Looks for classes in: core/, app/models/, app/controllers/
 */
spl_autoload_register(function (string $class): void {
    $searchPaths = [
        CORE_PATH,
        APP_PATH . '/models',
        APP_PATH . '/controllers',
        APP_PATH . '/services',
    ];

    foreach ($searchPaths as $dir) {
        $file = $dir . '/' . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
