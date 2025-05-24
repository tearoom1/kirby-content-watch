<?php

// support manual installation in plugins folder
@include_once __DIR__ . '/vendor/autoload.php';

// don't load plugin if it's disabled in the config.
if (option('tearoom1.content-history.disable', false)) {
    return;
}

Kirby::plugin('tearoom1/content-history', [
    'areas' => [
        'content-history' => require __DIR__ . '/src/areas/content-history.php',
    ],
    'options' => [
        'pagination' => 20,
    ],
]);
