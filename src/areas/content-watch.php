<?php

namespace TearoomOne\ContentWatch;

use TearoomOne\ContentWatch\ContentWatchController;

return [
    'label' => 'Content Watch',
    'icon' => 'text-justify',
    'menu' => true,
    'link' => 'content-watch',
    'views' => [
        [
            'pattern' => 'content-watch',
            'action' => function () {
                // Get content files
                $contentWatchController = new ContentWatchController();
                $files = $contentWatchController->getContentFiles();

                $lockedPages = (bool)option('tearoom1.content-watch.enableLockedPages', true) ? $contentWatchController->getLockedPages() : [];
                $retentionDays = (int)option('tearoom1.content-watch.retentionDays', 30);
                $retentionCount = (int)option('tearoom1.content-watch.retentionCount', 10);

                return [
                    'component' => 'content-watch',
                    'title' => 'Content Watch',
                    'props' => [
                        'lockedPages' => $lockedPages,
                        'files' => $files,
                        'retentionDays' => $retentionDays,
                        'retentionCount' => $retentionCount,
                        'enableRestore' => option('tearoom1.content-watch.enableRestore', false),
                    ],
                ];
            }
        ],
    ],
];
