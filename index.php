<?php

// support manual installation in plugins folder
use Kirby\Cms\ModelWithContent;

load([
    'TearoomOne\\ContentWatch\\ContentWatchController' => 'src/ContentWatchController.php',
], __DIR__);

// don't load plugin if it's disabled in the config.
if (option('tearoom1.content-watch.disable', false)) {
    return;
}

use TearoomOne\ContentWatch\ContentWatchController;

Kirby::plugin('tearoom1/kirby-content-watch', [
    'hooks' => [
        'page.create:after' => function ($page) {
            (new ContentWatchController())->trackContentChange($page);
        },
        'page.update:after' => function ($newPage, $oldPage) {
            (new ContentWatchController())->trackContentChange($newPage);
        },
        'site.update:after' => function ($newSite, $oldSite) {
            (new ContentWatchController())->trackContentChange($newSite);
        },
        'file.create:after' => function ($file) {
            (new ContentWatchController())->trackContentChange($file);
        },
        'file.update:after' => function ($newFile, $oldFile) {
            (new ContentWatchController())->trackContentChange($newFile);
        }
    ],
    'areas' => [
        'content-watch' => require __DIR__ . '/src/areas/content-watch.php',
    ],
    'options' => [
        'pagination' => 20,
        'retentionDays' => 30, // default to 30 days of history
        'enableLockedPages' => true,
    ],
]);
