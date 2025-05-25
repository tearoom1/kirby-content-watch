<?php

// support manual installation in plugins folder
use Kirby\Http\Response;
use TearoomOne\ContentWatch\ContentWatchController;

load([
    'TearoomOne\\ContentWatch\\ContentWatchController' => 'src/ContentWatchController.php',
], __DIR__);

// don't load plugin if it's disabled in the config.
if (option('tearoom1.content-watch.disable', false)) {
    return;
}

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
        'enableRestore' => false, // enable or disable the restore functionality
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => '/content-watch/restore',
                'method' => 'POST',
                'action' => function () {
                    // Get the current user
                    if (!$user = kirby()->user()) {
                        return Response::json([
                            'status' => 'error',
                            'message' => 'Unauthorized'
                        ], 401);
                    }

                    // Check if restore functionality is enabled
                    if (option('tearoom1.content-watch.enableRestore') !== true) {
                        return Response::json([
                            'status' => 'error',
                            'message' => 'Restore functionality is disabled'
                        ], 403);
                    }

                    // Get data from request
                    $request = kirby()->request();
                    $dirPath = $request->get('dirPath');
                    $fileKey = $request->get('fileKey');
                    $timestamp = (int)$request->get('timestamp');

                    // Validate data
                    if (!$dirPath || !$fileKey || !$timestamp) {
                        return Response::json([
                            'status' => 'error',
                            'message' => 'Missing required parameters'
                        ], 400);
                    }

                    // Restore content
                    $contentWatchController = new ContentWatchController();
                    $success = $contentWatchController->restoreContent($dirPath, $fileKey, $timestamp);

                    if ($success) {
                        return Response::json([
                            'status' => 'success',
                            'message' => 'Content restored successfully'
                        ]);
                    } else {
                        return Response::json([
                            'status' => 'error',
                            'message' => 'Failed to restore content'
                        ], 500);
                    }
                }
            ]
        ]
    ]
]);
