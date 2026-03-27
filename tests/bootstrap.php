<?php

error_reporting(E_ALL);
ini_set('display_errors', 'on');

// Suppress Kirby's PHP version check (we run on 8.5 which is above Kirby's checked upper bound)
define('KIRBY_PHP_VERSION_CHECK', false);

// Load Kirby and all project dependencies (including helpers like kirby(), option(), F::, etc.)
require_once __DIR__ . '/../../../../vendor/autoload.php';

// Load the plugin's own PSR-4 automap (TearoomOne\ContentWatch\*)
require_once __DIR__ . '/../vendor/autoload.php';

// Register the tests namespace manually (no composer autoload-dev available without install)
require_once __DIR__ . '/TestCase.php';
