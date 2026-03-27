<?php

@include_once __DIR__ . '/vendor/autoload.php';

load([
    'TearoomOne\\ContentWatch\\ContentWatchController' => 'src/ContentWatchController.php',
    'TearoomOne\\ContentWatch\\ContentRestore'         => 'src/ContentRestore.php',
    'TearoomOne\\ContentWatch\\ChangeTracker'          => 'src/ChangeTracker.php',
    'TearoomOne\\ContentWatch\\LockedPages'            => 'src/LockedPages.php',
    'TearoomOne\\ContentWatch\\DiffGenerator'          => 'src/DiffGenerator.php',
], __DIR__);

use Kirby\Filesystem\F;
use Kirby\Http\Response;
use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\DiffGenerator;

// Don't load plugin if disabled in config
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
        'page.changeTitle:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage);
        },
        'page.changeSlug:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage);
        },
        'page.move:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage, [
                'action' => 'moved',
            ]);
        },
        'page.delete:before' => function ($page) {
            F::remove($page->root() . '/.content-watch.json');
        },
        'site.update:after' => function ($newSite, $oldSite) {
            (new ChangeTracker())->trackContentChange($newSite);
        },
        'file.create:after' => function ($file) {
            (new ChangeTracker())->trackContentChange($file);
        },
        'file.update:after' => function ($newFile, $oldFile) {
            (new ChangeTracker())->trackContentChange($newFile);
        },
    ],
    'areas' => [
        'content-watch' => require __DIR__ . '/src/areas/content-watch.php',
    ],
    'pageMethods' => [
        'contentHistory' => function (string $language = null) {
            $historyFile = $this->root() . '/.content-watch.json';
            if (!F::exists($historyFile)) {
                return [];
            }

            $history = json_decode(F::read($historyFile), true) ?: [];
            $entries = $history[$this->intendedTemplate()->name()] ?? [];

            if ($language !== null) {
                $entries = array_filter($entries, fn($e) => ($e['language'] ?? '') === $language);
            }

            return array_values($entries);
        },
    ],
    'options' => [
        'retentionDays'      => 30,
        'retentionCount'     => 10,
        'enableLockedPages'  => true,
        'enableRestore'      => false,
        'enableDiff'         => true,
        'defaultPageSize'    => 20,
        'layoutStyle'        => 'compact',
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => '/content-watch/restore',
                'method'  => 'POST',
                'action'  => function () {
                    if (!kirby()->user()) {
                        return Response::json(['status' => 'error', 'message' => 'Unauthorized'], 401);
                    }

                    if (option('tearoom1.kirby-content-watch.enableRestore') !== true) {
                        return Response::json(['status' => 'error', 'message' => 'Restore functionality is disabled'], 403);
                    }

                    $request   = kirby()->request();
                    $dirPath   = $request->get('dirPath');
                    $fileKey   = $request->get('fileKey');
                    $timestamp = (int)$request->get('timestamp');

                    if (!$dirPath || !$fileKey || !$timestamp) {
                        return Response::json(['status' => 'error', 'message' => 'Missing required parameters'], 400);
                    }

                    $success = (new ContentRestore())->restoreContent($dirPath, $fileKey, $timestamp);

                    return $success
                        ? Response::json(['status' => 'success', 'message' => 'Content restored successfully'])
                        : Response::json(['status' => 'error', 'message' => 'Failed to restore content'], 500);
                },
            ],
            [
                'pattern' => '/content-watch/diff',
                'method'  => 'POST',
                'action'  => function () {
                    if (!kirby()->user()) {
                        return Response::json(['status' => 'error', 'message' => 'Unauthorized'], 401);
                    }

                    $request       = kirby()->request();
                    $dirPath       = $request->get('dirPath');
                    $fileKey       = $request->get('fileKey');
                    $fromTimestamp = (int)$request->get('fromTimestamp');
                    $toTimestamp   = (int)$request->get('toTimestamp');

                    if (!$dirPath || !$fileKey || !$fromTimestamp || !$toTimestamp) {
                        return Response::json(['status' => 'error', 'message' => 'Missing required parameters'], 400);
                    }

                    try {
                        $historyFile = $dirPath . '/.content-watch.json';
                        if (!F::exists($historyFile)) {
                            return Response::json(['status' => 'error', 'message' => 'History file not found'], 404);
                        }

                        $history = json_decode(F::read($historyFile), true) ?: [];
                        if (!isset($history[$fileKey])) {
                            return Response::json(['status' => 'error', 'message' => 'No history found for this file'], 404);
                        }

                        $fromVersion = null;
                        $toVersion   = null;
                        foreach ($history[$fileKey] as $entry) {
                            if ($entry['time'] === $fromTimestamp) {
                                $fromVersion = $entry;
                            }
                            if ($entry['time'] === $toTimestamp) {
                                $toVersion = $entry;
                            }
                        }

                        if (!$fromVersion || !$toVersion) {
                            return Response::json(['status' => 'error', 'message' => 'One or both versions not found'], 404);
                        }

                        $fromContent = $fromVersion['content'] ?? null;
                        $toContent   = $toVersion['content'] ?? null;

                        if ($fromContent === null || $toContent === null) {
                            return Response::json(['status' => 'error', 'message' => 'One or both versions have no content'], 404);
                        }

                        $diff = DiffGenerator::generate($fromContent, $toContent);

                        return Response::json([
                            'status' => 'success',
                            'diff'   => $diff,
                        ]);
                    } catch (\Exception $e) {
                        return Response::json(['status' => 'error', 'message' => 'Error generating diff: ' . $e->getMessage()], 500);
                    }
                },
            ],
        ],
    ],
]);
