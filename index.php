<?php

@include_once __DIR__ . '/vendor/autoload.php';

load([
    'TearoomOne\\ContentWatch\\ContentWatchController' => 'src/ContentWatchController.php',
    'TearoomOne\\ContentWatch\\ResolvesContentModels'  => 'src/ResolvesContentModels.php',
    'TearoomOne\\ContentWatch\\ContentRestore'         => 'src/ContentRestore.php',
    'TearoomOne\\ContentWatch\\ChangeTracker'          => 'src/ChangeTracker.php',
    'TearoomOne\\ContentWatch\\LockedPages'            => 'src/LockedPages.php',
    'TearoomOne\\ContentWatch\\DiffGenerator'          => 'src/DiffGenerator.php',
    'TearoomOne\\ContentWatch\\SnapshotSerializer'     => 'src/SnapshotSerializer.php',
    'TearoomOne\\ContentWatch\\ContentDiffResolver'    => 'src/ContentDiffResolver.php',
    'TearoomOne\\ContentWatch\\FieldLabels'            => 'src/FieldLabels.php',
    'TearoomOne\\ContentWatch\\StructuredDiff'         => 'src/StructuredDiff.php',
    'TearoomOne\\ContentWatch\\HistoryStore'           => 'src/HistoryStore.php',
    'TearoomOne\\ContentWatch\\RestoreException'       => 'src/RestoreException.php',
], __DIR__);

use Kirby\Cms\File;
use Kirby\Cms\Site;
use Kirby\Filesystem\F;
use Kirby\Toolkit\I18n;
use Kirby\Http\Response;
use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentDiffResolver;
use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\ContentWatchController;
use TearoomOne\ContentWatch\RestoreException;

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
            // Kirby copies the original's history files along, start fresh instead
            $tracker = new ChangeTracker();
            $tracker->resetHistory($duplicatePage);
            $tracker->trackContentChange($duplicatePage, [
                'action' => 'duplicated',
            ]);
        },
        'page.delete:before' => function ($page) {
            F::remove($page->root() . '/.content-watch.json');
            foreach (glob($page->root() . '/.content-watch.corrupt-*.json') ?: [] as $corrupt) {
                F::remove($corrupt);
            }
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
        'file.delete:after' => function ($status, $file) {
            (new ChangeTracker())->forgetFile($file);
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
    'translations' => [
        'en' => [
            'tearoom1.content-watch.support'        => 'Support this plugin',
            'tearoom1.content-watch.view.page'      => 'By page',
            'tearoom1.content-watch.view.timeline'  => 'Timeline',
            'tearoom1.content-watch.timeline.today' => 'Today',
            'tearoom1.content-watch.timeline.yesterday' => 'Yesterday',
            'tearoom1.content-watch.timeline.pages' => '{count} pages',
            'tearoom1.content-watch.timeline.empty' => 'No changes found',
            'tearoom1.content-watch.restore.button'         => 'Restore this version',
            'tearoom1.content-watch.restore.hint'           => 'Files stay unchanged.',
            'tearoom1.content-watch.restore.hint.language'  => 'Only {language} is affected. Files and other languages stay unchanged.',
            'tearoom1.content-watch.restore.confirm.title'  => 'Restore v{version}?',
            'tearoom1.content-watch.restore.confirm.text'   => 'The page will get the content of v{version}.',
            'tearoom1.content-watch.restore.confirm.text.language' => 'The page will get the content of v{version} in {language}.',
            'tearoom1.content-watch.restore.confirm.history' => 'The current version is kept in the history, so you can go back.',
            'tearoom1.content-watch.restore.confirm.languages' => 'Other languages stay as they are.',
            'tearoom1.content-watch.restore.confirm.submit' => 'Restore v{version}',
            'tearoom1.content-watch.restore.done'           => 'v{version} restored',
            'tearoom1.content-watch.editor.outside' => 'Outside the Panel',
            'tearoom1.content-watch.editor.outside.help' => 'Changed directly in the file system, e.g. by a deploy, FTP or git',
            'tearoom1.content-watch.editor.deleted' => 'Deleted user',
            'tearoom1.content-watch.field.path'     => 'Parent page',
            'tearoom1.content-watch.diff.version'           => 'Version',
            'tearoom1.content-watch.diff.compare'           => 'Compare with',
            'tearoom1.content-watch.diff.current'           => 'Current version',
            'tearoom1.content-watch.diff.summary.changed'   => '{count} fields changed since v{version}',
            'tearoom1.content-watch.diff.summary.changed.one' => '1 field changed since v{version}',
            'tearoom1.content-watch.diff.summary.unchanged' => '{count} unchanged fields hidden',
            'tearoom1.content-watch.diff.summary.unchanged.one' => '1 unchanged field hidden',
            'tearoom1.content-watch.diff.summary.none'      => 'No changes',
            'tearoom1.content-watch.diff.changed'           => 'changed',
            'tearoom1.content-watch.diff.added'             => 'added',
            'tearoom1.content-watch.diff.removed'           => 'removed',
            'tearoom1.content-watch.diff.moved'             => 'moved',
            'tearoom1.content-watch.diff.block'             => 'Block {n}',
            'tearoom1.content-watch.diff.block.was'         => 'was block {n}',
            'tearoom1.content-watch.diff.row'               => 'Entry {n}',
            'tearoom1.content-watch.diff.row.was'           => 'was entry {n}',
            'tearoom1.content-watch.diff.blocks.changed'    => '{changed} of {total} blocks changed',
            'tearoom1.content-watch.diff.rows.changed'      => '{changed} of {total} entries changed',
            'tearoom1.content-watch.diff.blocks.unchanged'  => '{count} unchanged blocks ({types})',
            'tearoom1.content-watch.diff.blocks.unchanged.one' => '1 unchanged block ({types})',
            'tearoom1.content-watch.diff.rows.unchanged'    => '{count} unchanged entries',
            'tearoom1.content-watch.diff.rows.unchanged.one' => '1 unchanged entry',
        ],
        'de' => [
            'tearoom1.content-watch.support'        => 'Plugin unterstützen',
            'tearoom1.content-watch.view.page'      => 'Nach Seite',
            'tearoom1.content-watch.view.timeline'  => 'Zeitleiste',
            'tearoom1.content-watch.timeline.today' => 'Heute',
            'tearoom1.content-watch.timeline.yesterday' => 'Gestern',
            'tearoom1.content-watch.timeline.pages' => '{count} Seiten',
            'tearoom1.content-watch.timeline.empty' => 'Keine Änderungen gefunden',
            'tearoom1.content-watch.restore.button'         => 'Diese Version wiederherstellen',
            'tearoom1.content-watch.restore.hint'           => 'Dateien bleiben unverändert.',
            'tearoom1.content-watch.restore.hint.language'  => 'Nur {language} ist betroffen. Dateien und andere Sprachen bleiben unverändert.',
            'tearoom1.content-watch.restore.confirm.title'  => 'v{version} wiederherstellen?',
            'tearoom1.content-watch.restore.confirm.text'   => 'Die Seite erhält den Inhalt von v{version}.',
            'tearoom1.content-watch.restore.confirm.text.language' => 'Die Seite erhält den Inhalt von v{version} auf {language}.',
            'tearoom1.content-watch.restore.confirm.history' => 'Die aktuelle Version bleibt in der Historie, du kannst also zurück.',
            'tearoom1.content-watch.restore.confirm.languages' => 'Andere Sprachen bleiben, wie sie sind.',
            'tearoom1.content-watch.restore.confirm.submit' => 'v{version} wiederherstellen',
            'tearoom1.content-watch.restore.done'           => 'v{version} wiederhergestellt',
            'tearoom1.content-watch.editor.outside' => 'Außerhalb des Panels',
            'tearoom1.content-watch.editor.outside.help' => 'Direkt im Dateisystem geändert, z. B. durch ein Deployment, FTP oder Git',
            'tearoom1.content-watch.editor.deleted' => 'Gelöschter Benutzer',
            'tearoom1.content-watch.field.path'     => 'Übergeordnete Seite',
            'tearoom1.content-watch.diff.version'           => 'Version',
            'tearoom1.content-watch.diff.compare'           => 'Vergleichen mit',
            'tearoom1.content-watch.diff.current'           => 'Aktuelle Version',
            'tearoom1.content-watch.diff.summary.changed'   => '{count} Felder seit v{version} geändert',
            'tearoom1.content-watch.diff.summary.changed.one' => '1 Feld seit v{version} geändert',
            'tearoom1.content-watch.diff.summary.unchanged' => '{count} unveränderte Felder ausgeblendet',
            'tearoom1.content-watch.diff.summary.unchanged.one' => '1 unverändertes Feld ausgeblendet',
            'tearoom1.content-watch.diff.summary.none'      => 'Keine Änderungen',
            'tearoom1.content-watch.diff.changed'           => 'geändert',
            'tearoom1.content-watch.diff.added'             => 'hinzugefügt',
            'tearoom1.content-watch.diff.removed'           => 'entfernt',
            'tearoom1.content-watch.diff.moved'             => 'verschoben',
            'tearoom1.content-watch.diff.block'             => 'Block {n}',
            'tearoom1.content-watch.diff.block.was'         => 'war Block {n}',
            'tearoom1.content-watch.diff.row'               => 'Eintrag {n}',
            'tearoom1.content-watch.diff.row.was'           => 'war Eintrag {n}',
            'tearoom1.content-watch.diff.blocks.changed'    => '{changed} von {total} Blöcken geändert',
            'tearoom1.content-watch.diff.rows.changed'      => '{changed} von {total} Einträgen geändert',
            'tearoom1.content-watch.diff.blocks.unchanged'  => '{count} unveränderte Blöcke ({types})',
            'tearoom1.content-watch.diff.blocks.unchanged.one' => '1 unveränderter Block ({types})',
            'tearoom1.content-watch.diff.rows.unchanged'    => '{count} unveränderte Einträge',
            'tearoom1.content-watch.diff.rows.unchanged.one' => '1 unveränderter Eintrag',
        ],
    ],
    'areas' => [
        'content-watch' => require __DIR__ . '/src/areas/content-watch.php',
    ],
    'sections' => [
        'contentwatch' => [
            'props' => [
                'headline' => fn ($headline = 'Recent changes') => I18n::translate($headline, $headline),
                'limit'    => fn (int $limit = 5) => $limit,
            ],
            'computed' => [
                'canAccess' => fn () => ContentWatchController::canAccess(),
                'entries'   => function () {
                    if (!ContentWatchController::canAccess()) {
                        return [];
                    }

                    $entries = (new ContentWatchController())->getModelHistory($this->model());

                    // the diff of the last shown entry needs the one before it
                    foreach ($entries as $index => $entry) {
                        $previous = $entries[$index + 1] ?? null;

                        $entries[$index]['previous'] = $previous ? [
                            'entry_id'     => $previous['entry_id'],
                            'time'         => $previous['time'],
                            'version'      => $previous['version'],
                            'has_snapshot' => $previous['has_snapshot'],
                        ] : null;
                    }

                    return array_slice($entries, 0, max(1, $this->limit));
                },
                'history'   => function () {
                    if (!ContentWatchController::canAccess()) {
                        return null;
                    }

                    [$dirPath, $fileKey] = (new ContentWatchController())->modelHistoryLocation($this->model());

                    // same rules as in the Content Watch area: diffs are part of restoring
                    $enableRestore = option('tearoom1.kirby-content-watch.enableRestore') === true;

                    return [
                        'dirPath'       => $dirPath,
                        'fileKey'       => $fileKey,
                        'enableDiff'    => $enableRestore && option('tearoom1.kirby-content-watch.enableDiff', true) === true,
                        'enableRestore' => $enableRestore,
                    ];
                },
                'areaUrl'   => function () {
                    $model  = $this->model();
                    $search = match (true) {
                        $model instanceof File => $model->filename(),
                        $model instanceof Site => 'Site',
                        default                => $model->title()->value(),
                    };

                    return 'content-watch?' . http_build_query([
                        'search' => $search,
                        'all'    => $model instanceof File ? 1 : null,
                    ]);
                },
            ],
        ],
    ],
    'pageMethods' => [
        'contentHistory' => function (?string $language = null) {
            $historyFile = $this->root() . '/.content-watch.json';
            if (!F::exists($historyFile)) {
                return [];
            }

            $history = \TearoomOne\ContentWatch\HistoryStore::read($this->root());
            $entries = $history[$this->intendedTemplate()->name()] ?? [];

            if ($language !== null) {
                $entries = array_filter($entries, fn($e) => ($e['language'] ?? '') === $language);
            }

            return array_values($entries);
        },
    ],
    'options' => [
        'allowedRoles'       => [], // Additional Kirby roles allowed to use the plugin (admins always allowed). Example: ['editor', 'client']
        'retentionDays'      => 30,
        'retentionCount'     => 10,
        'enableLockedPages'  => null, // null = auto: on for Kirby 4, off for Kirby 5 (which has its own "Changes" view)
        'enableRestore'      => false,
        'enableDiff'         => true,
        'defaultPageSize'    => 20,
        'layoutStyle'        => 'compact', // deprecated: the list is always compact, 'relaxed' and 'default' are ignored
        'defaultView'        => 'page', // 'page' or 'timeline'
        'notify'             => null, // Webhook URL (JSON POST) or callable receiving the change payload
    ],
    'api' => [
        'routes' => [
            [
                'pattern' => '/content-watch/restore',
                'method'  => 'POST',
                'auth'    => true,
                'action'  => function () {
                    if (!ContentWatchController::canAccess()) {
                        return Response::json(['status' => 'error', 'message' => 'Forbidden'], 403);
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

                    try {
                        (new ContentRestore())->restore(
                            $dirPath,
                            $fileKey,
                            is_string($entryId) ? $entryId : null,
                            $timestamp ?: null
                        );
                    } catch (RestoreException $e) {
                        $code = $e->getCode();
                        $status = is_int($code) && $code >= 400 && $code < 600 ? $code : 400;

                        return Response::json(['status' => 'error', 'message' => $e->getMessage()], $status);
                    }

                    return Response::json(['status' => 'success', 'message' => 'Content restored successfully']);
                },
            ],
            [
                'pattern' => '/content-watch/diff',
                'method'  => 'POST',
                'auth'    => true,
                'action'  => function () {
                    if (!ContentWatchController::canAccess()) {
                        return Response::json(['status' => 'error', 'message' => 'Forbidden'], 403);
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
                        $result = (new ContentDiffResolver())->compare(
                            $dirPath,
                            $fileKey,
                            is_string($fromEntryId) ? $fromEntryId : null,
                            is_string($toEntryId) ? $toEntryId : null,
                            $fromTimestamp ?: null,
                            $toTimestamp ?: null
                        );

                        return Response::json([
                            'status'  => 'success',
                            'diff'    => $result['html'],
                            'summary' => [
                                'changed'   => $result['changed'],
                                'unchanged' => $result['unchanged'],
                            ],
                            'canRestore' => $result['canRestore'],
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
