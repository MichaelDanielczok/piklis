<?php

/**
 * Loads the QUIQQER instance this package is installed in (autoloader,
 * constants). Unit tests must not depend on database content.
 */

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

// Instanz-Root: im Paketordner packages/<vendor>/<package>, in Docker über
// QUIQQER_BOOTSTRAP (das Paket ist dort ein Symlink, siehe quiqqer-docker-dev)
require_once getenv('QUIQQER_BOOTSTRAP') ?: __DIR__ . '/../../../../bootstrap.php';

// Hilfsklassen der Tests (tests/unit/**/Fixtures), nicht im Composer-Autoloader der Instanz
spl_autoload_register(static function (string $class): void {
    $prefix = 'MichaelDanielczok\\Piklis\\Tests\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', lcfirst(substr($class, strlen($prefix)))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
