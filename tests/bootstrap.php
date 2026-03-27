<?php

error_reporting(E_ALL);
ini_set('display_errors', 'on');

// Suppress Kirby's PHP version check (we run on 8.5 which is above Kirby's checked upper bound)
define('KIRBY_PHP_VERSION_CHECK', false);

// Support both a standalone plugin checkout and a plugin nested inside a Kirby site.
$autoloadFiles = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

foreach ($autoloadFiles as $autoloadFile) {
    if (is_file($autoloadFile)) {
        require_once $autoloadFile;
    }
}

if (class_exists(\Kirby\Cms\App::class) !== true) {
    throw new RuntimeException('Unable to locate a Composer autoloader with Kirby CMS classes.');
}

// Register the tests namespace manually (no composer autoload-dev available without install)
require_once __DIR__ . '/TestCase.php';
