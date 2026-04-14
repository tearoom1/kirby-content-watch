<?php

@include_once __DIR__ . '/vendor/autoload.php';

load([
    'TearoomOne\\ContentWatch\\ContentWatchController' => 'src/ContentWatchController.php',
    'TearoomOne\\ContentWatch\\ContentRestore'         => 'src/ContentRestore.php',
    'TearoomOne\\ContentWatch\\ChangeTracker'          => 'src/ChangeTracker.php',
    'TearoomOne\\ContentWatch\\LockedPages'            => 'src/LockedPages.php',
    'TearoomOne\\ContentWatch\\DiffGenerator'          => 'src/DiffGenerator.php',
    'TearoomOne\\ContentWatch\\SnapshotSerializer'     => 'src/SnapshotSerializer.php',
    'TearoomOne\\ContentWatch\\ContentDiffResolver'    => 'src/ContentDiffResolver.php',
], __DIR__);

use Kirby\Filesystem\F;
use Kirby\Http\Response;
use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentDiffResolver;
use TearoomOne\ContentWatch\ContentRestore;

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
            (new ChangeTracker())->trackContentChange($newPage, [
                'coalesce_group' => 'page-title-slug',
                'coalesce_key'   => $oldPage->root(),
            ]);
        },
        'page.changeSlug:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage, [
                'coalesce_group' => 'page-title-slug',
                'coalesce_key'   => $oldPage->root(),
            ]);
        },
        'page.changeStatus:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage);
        },
        'page.changeTemplate:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage, [
                'previous_file_key' => $oldPage->intendedTemplate()->name(),
            ]);
        },
        'page.move:after' => function ($newPage, $oldPage) {
            (new ChangeTracker())->trackContentChange($newPage, [
                'action' => 'moved',
            ]);
        },
        'page.duplicate:after' => function ($duplicatePage, $originalPage) {
            // do nothing as change title will already track the change
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
        'file.changeName:after' => function ($newFile, $oldFile) {
            (new ChangeTracker())->trackContentChange($newFile, [
                'previous_file_key' => $oldFile->filename(),
            ]);
        },
        'file.changeSort:after' => function ($newFile, $oldFile) {
            (new ChangeTracker())->trackContentChange($newFile);
        },
        'file.changeTemplate:after' => function ($newFile, $oldFile) {
            (new ChangeTracker())->trackContentChange($newFile, [
                'previous_file_key' => $oldFile->filename(),
            ]);
        },
        'file.replace:after' => function ($newFile, $oldFile) {
            (new ChangeTracker())->trackContentChange($newFile);
        },
    ],
    'areas' => [
        'content-watch' => require __DIR__ . '/src/areas/content-watch.php',
    ],
    'pageMethods' => [
        'contentHistory' => function (?string $language = null) {
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
                    $entryId   = $request->get('entryId');
                    $timestamp = (int)$request->get('timestamp');

                    if (!$dirPath || !$fileKey || (!$entryId && !$timestamp)) {
                        return Response::json(['status' => 'error', 'message' => 'Missing required parameters'], 400);
                    }

                    $success = (new ContentRestore())->restoreContent(
                        $dirPath,
                        $fileKey,
                        is_string($entryId) ? $entryId : null,
                        $timestamp ?: null
                    );

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
                    $fromEntryId   = $request->get('fromEntryId');
                    $toEntryId     = $request->get('toEntryId');
                    $fromTimestamp = (int)$request->get('fromTimestamp');
                    $toTimestamp   = (int)$request->get('toTimestamp');

                    if (
                        !$dirPath ||
                        !$fileKey ||
                        (!$fromEntryId && !$fromTimestamp) ||
                        (!$toEntryId && !$toTimestamp)
                    ) {
                        return Response::json(['status' => 'error', 'message' => 'Missing required parameters'], 400);
                    }

                    try {
                        $diff = (new ContentDiffResolver())->generate(
                            $dirPath,
                            $fileKey,
                            is_string($fromEntryId) ? $fromEntryId : null,
                            is_string($toEntryId) ? $toEntryId : null,
                            $fromTimestamp ?: null,
                            $toTimestamp ?: null
                        );

                        return Response::json([
                            'status' => 'success',
                            'diff'   => $diff,
                        ]);
                    } catch (\Exception $e) {
                        $code = $e->getCode();
                        $status = is_int($code) && $code >= 400 && $code < 600 ? $code : 500;
                        $message = $status === 500
                            ? 'Error generating diff: ' . $e->getMessage()
                            : $e->getMessage();

                        return Response::json(['status' => 'error', 'message' => $message], $status);
                    }
                },
            ],
        ],
    ],
]);
