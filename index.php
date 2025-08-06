<?php

@include_once __DIR__ . '/vendor/autoload.php';

load([
    'TearoomOne\\ContentWatch\\ContentWatchController' => 'src/ContentWatchController.php',
    'TearoomOne\\ContentWatch\\ContentRestore' => 'src/ContentRestore.php',
    'TearoomOne\\ContentWatch\\ChangeTracker' => 'src/ChangeTracker.php',
    'TearoomOne\\ContentWatch\\LockedPages' => 'src/LockedPages.php',
    'TearoomOne\\ContentWatch\\DiffGenerator' => 'src/DiffGenerator.php',
], __DIR__);

use Kirby\Http\Response;
use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\DiffGenerator;
use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;

// don't load plugin if it's disabled in the config.
if (option('tearoom1.kirby-content-watch.disable', false)) {
    return;
}

Kirby::plugin('tearoom1/kirby-content-watch', [
    'hooks' => [
        'page.create:after' => function ($page) {
            (new ChangeTracker())->trackContentChange($page);
        },
        'page.update:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage);
        },
        'site.update:after' => function ($newSite, $oldSite) {
            (new ChangeTracker())->trackContentChange($newSite);
        },
        'file.create:after' => function ($file) {
            (new ChangeTracker())->trackContentChange($file);
        },
        'file.update:after' => function ($newFile, $oldFile) {
            (new ChangeTracker())->trackContentChange($newFile);
        }
    ],
    'areas' => [
        'content-watch' => require __DIR__ . '/src/areas/content-watch.php',
    ],
    'options' => [
        'retentionDays' => 30, // default to 30 days of history
        'retentionCount' => 10,
        'enableLockedPages' => true,
        'enableRestore' => false, // enable or disable the restore functionality
        'enableDiff' => true, // enable or disable the diff functionality
        'defaultPageSize' => 20,
        'layoutStyle' => 'compact',
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
                    if (option('tearoom1.kirby-content-watch.enableRestore') !== true) {
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
                    $contentRestore = new ContentRestore();
                    $success = $contentRestore->restoreContent($dirPath, $fileKey, $timestamp);

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
            ],
            [
                'pattern' => '/content-watch/diff',
                'method' => 'POST',
                'action' => function () {
                    // Get the current user
                    if (!$user = kirby()->user()) {
                        return Response::json([
                            'status' => 'error',
                            'message' => 'Unauthorized'
                        ], 401);
                    }

                    // Get data from request
                    $request = kirby()->request();
                    $dirPath = $request->get('dirPath');
                    $fileKey = $request->get('fileKey');
                    $fromTimestamp = (int)$request->get('fromTimestamp');
                    $toTimestamp = (int)$request->get('toTimestamp');

                    // Validate data
                    if (!$dirPath || !$fileKey || !$fromTimestamp || !$toTimestamp) {
                        return Response::json([
                            'status' => 'error',
                            'message' => 'Missing required parameters'
                        ], 400);
                    }

                    // Get content versions and generate diff
                    try {
                        $historyFile = $dirPath . '/.content-watch.json';
                        if (!file_exists($historyFile)) {
                            return Response::json([
                                'status' => 'error',
                                'message' => 'History file not found'
                            ], 404);
                        }

                        $history = json_decode(file_get_contents($historyFile), true) ?: [];
                        if (!isset($history[$fileKey])) {
                            return Response::json([
                                'status' => 'error',
                                'message' => 'No history found for this file'
                            ], 404);
                        }

                        // Find the two versions
                        $fromVersion = null;
                        $toVersion = null;
                        foreach ($history[$fileKey] as $entry) {
                            if ($entry['time'] === $fromTimestamp) {
                                $fromVersion = $entry;
                            }
                            if ($entry['time'] === $toTimestamp) {
                                $toVersion = $entry;
                            }
                        }

                        if (!$fromVersion || !$toVersion) {
                            return Response::json([
                                'status' => 'error',
                                'message' => 'One or both versions not found'
                            ], 404);
                        }

                        // Extract content from versions - handle different content structures
                        $fromContent = $fromVersion['content'] ?? null;
                        $toContent = $toVersion['content'] ?? null;

                        if ($fromContent === null || $toContent === null) {
                            return Response::json([
                                'status' => 'error',
                                'message' => 'One or both versions have no content'
                            ], 404);
                        }

                        // Generate the diff using the new DiffGenerator class
                        $diff = DiffGenerator::generate($fromContent, $toContent);

                        return Response::json([
                            'status' => 'success',
                            'diff' => $diff
                        ]);
                    } catch (\Exception $e) {
                        return Response::json([
                            'status' => 'error',
                            'message' => 'Error generating diff: ' . $e->getMessage()
                        ], 500);
                    }
                }
            ]
        ]
    ]
]);

