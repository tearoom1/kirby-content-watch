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
                list($files, $allHistoryEntries) = $contentWatchController->getContentFiles();

                // Get retention days setting
                $retentionDays = (int)option('tearoom1.content-watch.retentionDays', 30);
                $retentionCount = (int)option('tearoom1.content-watch.retentionCount', 10);

                return [
                    'component' => 'content-watch',
                    'title' => 'Content Watch',
                    'props' => [
                        'lockedPages' => (bool)option('tearoom1.content-watch.enableLockedPages', true) ? $contentWatchController->getLockedPages() : [],
                        'files' => $files,
                        'historyEntries' => $allHistoryEntries,
                        'retentionDays' => $retentionDays,
                        'retentionCount' => $retentionCount
                    ],
                ];
            }
        ],
    ],
];
