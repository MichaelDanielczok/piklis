<?php

/**
 * Loads the QUIQQER instance this package is installed in, so PHPStan knows
 * the classes of QUIQQER and the other packages.
 */

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

putenv('QUIQQER_OTHER_AUTOLOADERS=KEEP');

// Instanz-Root: im Paketordner packages/<vendor>/<package>, in Docker über
// QUIQQER_BOOTSTRAP (das Paket ist dort ein Symlink, siehe quiqqer-docker-dev)
require_once getenv('QUIQQER_BOOTSTRAP') ?: __DIR__ . '/../../../../bootstrap.php';
