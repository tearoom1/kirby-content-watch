<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;
use Kirby\Filesystem\F;

class ContentWatchController
{
    use ResolvesContentModels;

    /**
     * Check whether the current user may access the plugin
     * (view content history, run diffs, restore). Admins always pass.
     * Additional roles can be granted via the `allowedRoles` option.
     */
    public static function canAccess(): bool
    {
        $user = kirby()->user();
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $allowed = option('tearoom1.kirby-content-watch.allowedRoles', []);
        if (!is_array($allowed) || empty($allowed)) {
            return false;
        }
        return in_array($user->role()->name(), $allowed, true);
    }

    /**
     * @deprecated The list always uses the compact layout now. `relaxed` and
     *             `default` are still accepted in the config but have no effect.
     */
    public static function layoutStyle(): string
    {
        return 'compact';
    }

    /**
     * View the Content Watch area opens with: `page` (default) or `timeline`.
     * Editors can switch it in the Panel; their choice is kept in the browser.
     */
    public static function defaultView(): string
    {
        return option('tearoom1.kirby-content-watch.defaultView', 'page') === 'timeline' ? 'timeline' : 'page';
    }

    /**
     * @return array[]
     */
    public function getContentFiles(): array
    {
        $contentDir = kirby()->root('content');
        $files      = [];

        $historyFiles  = [];
        $contentFiles  = [];

        // with detection on, folders unchanged since the last check are skipped
        $index = ExternalChanges::isEnabled() ? ScanIndex::load() : null;
        $stats = [];

        // Single pass: collect history files and eligible content files together
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($contentDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ($index !== null && ScanIndex::counts($file->getBasename())) {
                $stats[$file->getPath()][$file->getBasename()] = [$file->getMTime(), $file->getCTime(), $file->getSize()];
            }

            if ($file->getBasename() === '.content-watch.json') {
                try {
                    $history  = HistoryStore::read(dirname($file->getPathname()));
                    $dirPath  = dirname($file->getPathname());
                    $historyFiles[$dirPath] = $history;
                } catch (\Exception) {
                    // Skip corrupt history files
                }
                continue;
            }

            if (
                $this->isDefaultContentFile($file) &&
                !str_ends_with(dirname($file->getPathname()), '_changes')
            ) {
                $contentFiles[] = $file;
            }
        }

        $this->checkedDirs = [];
        $this->failedDirs  = [];
        $signatures        = [];

        foreach ($contentFiles as $file) {
            $dirPath = dirname($file->getPathname());
            $signatures[$dirPath] ??= $index !== null ? ScanIndex::signature($stats[$dirPath] ?? []) : null;
            $verified = $index !== null && $index->isUnchanged($dirPath, $signatures[$dirPath]);

            $this->processFile($file, $contentDir, $historyFiles, $files, $verified);
        }

        if ($index !== null) {
            foreach ($signatures as $dirPath => $signature) {
                // a folder whose check failed is checked again next time
                if (isset($this->failedDirs[$dirPath])) {
                    continue;
                }

                // the check wrote the history file: take the signature again
                $dirStats = isset($this->checkedDirs[$dirPath]) ? ScanIndex::statsOf($dirPath) : ($stats[$dirPath] ?? []);

                // written just now: a second write in the same second could look the same
                if (ScanIndex::isSettled($dirStats)) {
                    $index->remember($dirPath, ScanIndex::signature($dirStats));
                }
            }

            $index->save();
        }

        $files = array_values($files);

        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /** @var array<string, true> folders whose history a check of this scan changed */
    protected array $checkedDirs = [];

    /** @var array<string, true> folders whose check failed in this scan */
    protected array $failedDirs = [];

    /**
     * @param bool $verified the folder is unchanged since its last check
     *                       (scan index): no check, no content file is read
     */
    public function processFile(
        mixed   $file,
        ?string $contentDir,
        array   $historyFiles,
        array   &$files,
        bool    $verified = false
    ): void {
        $filePath     = $file->getPathname();
        $dirPath      = dirname($filePath);
        $relativePath = str_replace($contentDir . '/', '', $filePath);
        $owner       = $this->findContentModelByRoot($dirPath);

        // Skip folders that are no Kirby model, e.g. snapshots other plugins
        // keep inside the content folder (kirby-revisions' _versions)
        if ($owner === null) {
            return;
        }
        $fallbackId  = $this->fallbackModelId(dirname($relativePath));
        $fileId      = $owner instanceof Site ? 'site' : ($owner?->id() ?? $fallbackId);
        $pathShort   = $fileId;
        $pathId    = str_replace('/', '+', $pathShort);

        // A file that has a sibling without extension is a media file (image, PDF, etc.)
        $isMediaFile = F::exists(preg_replace('%(\.[a-z]{2})?\.txt$%', '', $filePath));

        $record = null;
        $page   = null;

        if ($isMediaFile) {
            $fileKey = preg_replace('%(\.[a-z]{2})?\.txt$%', '', basename($relativePath));
            $title   = 'File: ' . $fileKey;
            $fileId  = $fileKey;
            $panelUrl = $owner?->file($fileKey)?->panel()->url()
                ?? kirby()->url('panel') . '/pages/' . $pathId . '/files/' . $fileKey;
        } elseif ($owner instanceof Site) {
            $fileId    = 'site';
            $fileKey   = 'site';
            $pathShort = 'site';
            $title     = 'Site';
            $panelUrl  = $owner->panel()->url();
        } else {
            $page  = $owner instanceof Page ? $owner : null;
            $title = 'Unknown';
            // Use intendedTemplate() so the key matches what ChangeTracker stores
            $fileKey = basename($fileId);

            if ($page) {
                $title    = $page->title()->value();
                $fileKey  = $page->intendedTemplate()->name();
                $panelUrl = $page->panel()->url();
            } else {
                $panelUrl = kirby()->url('panel') . '/pages/' . $pathId;
            }
        }

        // Record changes made outside the Panel first, so the list shows them,
        // and the starting point of content without history
        if (ExternalChanges::isEnabled() && $verified !== true) {
            $model = $isMediaFile ? $owner->file($fileKey) : $owner;

            if ($model !== null) {
                $external = new ExternalChanges();

                if ($external->check($model, $historyFiles[$dirPath] ?? null)) {
                    $historyFiles[$dirPath]       = HistoryStore::read($dirPath);
                    $this->checkedDirs[$dirPath] = true;
                }

                if ($external->failed) {
                    $this->failedDirs[$dirPath] = true;
                }
            }
        }

        // Resolve history for this file
        $historyEntries = $this->applyRetention($historyFiles[$dirPath][$fileKey] ?? []);

        // the newest change; a baseline is the state as found, not a change
        foreach ($historyEntries as $entry) {
            if (($entry['source'] ?? null) !== 'baseline') {
                $record = $entry;
                break;
            }
        }

        $modified = $record !== null ? $record['time'] : $file->getMTime();
        $editor   = $this->getEditor($record);

        // unchanged since the last check: the newest entry of each language
        // holds the hash of the content file, no need to read it
        $knownHashes = $verified && !$isMediaFile ? self::latestHashes($historyFiles[$dirPath][$fileKey] ?? []) : [];

        $historyEntriesBuilt = $this->buildHistoryEntries($historyEntries, $isMediaFile ? null : $owner, $dirPath, $fileKey, $knownHashes);

        $pathParts = explode('/', $relativePath);

        $files[$dirPath . '/' . $fileKey] = [
            'id'                 => $fileId,
            'uid'                => $fileKey,
            'path_short'         => $pathShort,
            'path'               => dirname($relativePath),
            'page_status'        => $page ? $this->pageStatus($page) : null,
            'page_template'      => $page ? $page->intendedTemplate()->name() : null,
            'title'              => $title,
            'parent'             => end($pathParts) ?: 'root',
            'modified'           => $modified,
            'modified_formatted' => date('Y-m-d H:i:s', $modified),
            'editor'             => $editor,
            'panel_url'          => $panelUrl,
            'dir_path'           => $dirPath,
            'is_media_file'      => $isMediaFile,
            'history'            => $historyEntriesBuilt,
        ];
    }

    /**
     * Prepare raw history entries for the panel.
     */
    public function buildHistoryEntries(
        array $entries,
        ?ModelWithContent $model = null,
        ?string $dirPath = null,
        ?string $fileKey = null,
        array $knownHashes = []
    ): array {
        $built   = [];
        $current = new CurrentContent($model, $dirPath, $fileKey);

        foreach ($knownHashes as $language => $hash) {
            $current->assume((string)$language, $hash);
        }
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $built[] = [
                'entry_id'       => $entry['uuid'] ?? null,
                'editor'         => $this->getEditor($entry),
                'time'           => $entry['time'] ?? 0,
                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0),
                'has_snapshot'   => !empty($entry['content']),
                'restored_from'  => $entry['restored_from'] ?? null,
                'restored_from_id' => $entry['restored_from_id'] ?? null,
                'action'         => $entry['action'] ?? null,
                // `baseline`: the starting point, `external`: changed outside the Panel
                'source'         => $entry['source'] ?? null,
                'version'        => $entry['version'] ?? 1,
                'language'       => $entry['language'] ?? '',
                // the snapshot equals the content as it is now: nothing to compare or restore
                'same_as_current' => $current->equals($entry),
            ];
        }

        return $built;
    }

    /**
     * History of a single page, site or file, newest first.
     */
    public function getModelHistory(ModelWithContent $model): array
    {
        [$dirPath, $fileKey] = $this->modelHistoryLocation($model);

        if ($dirPath === null) {
            return [];
        }

        try {
            $history = F::exists($dirPath . '/.content-watch.json') ? HistoryStore::read($dirPath) : null;

            // with detection on, this also stores the starting point of a model without history
            if ((new ExternalChanges())->check($model, $history)) {
                $history = HistoryStore::read($dirPath);
            }
        } catch (\Exception) {
            return [];
        }

        if ($history === null) {
            return [];
        }

        return $this->buildHistoryEntries($this->applyRetention($history[$fileKey] ?? []), $model, $dirPath, $fileKey);
    }

    /**
     * Directory of the history file and the key of the model's entries in it,
     * as expected by the diff and restore API.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function modelHistoryLocation(ModelWithContent $model): array
    {
        return match (true) {
            $model instanceof Site => [$model->root(), 'site'],
            $model instanceof Page => [$model->root(), $model->intendedTemplate()->name()],
            $model instanceof File => [dirname($model->root()), $model->filename()],
            default                => [null, null],
        };
    }

    /**
     * Hide entries outside the retention window. Stored history is only pruned
     * on the next change, so expired entries would otherwise stay visible.
     */
    public function applyRetention(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }

        // same rules as when storing, see ChangeTracker::applyRetention()
        return ChangeTracker::applyRetention($entries);
    }

    /**
     * Hash of the newest entry of each language: the hash of its content
     * file, as long as the folder is unchanged since it was checked
     *
     * @return array<string, string>
     */
    public static function latestHashes(mixed $entries): array
    {
        $hashes = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $language = is_array($entry) ? (string)($entry['language'] ?? '') : null;

            if ($language === null || array_key_exists($language, $hashes)) {
                continue;
            }

            $hashes[$language] = is_string($entry['hash'] ?? null) ? $entry['hash'] : null;
        }

        return array_filter($hashes, 'is_string');
    }

    /**
     * Editor of a history entry
     *
     * Entries without a Panel user come from changes outside the Panel
     * (deploy, FTP, git, scripts running as the `kirby` user) and get
     * `outside: true`; entries of users that no longer exist get `deleted: true`;
     * baselines (starting points) get `baseline: true`.
     * The Panel translates both, see js/editor.js.
     */
    public function getEditor(mixed $record): array
    {
        // the starting point: the content as Content Watch first found it
        if (is_array($record) && ($record['source'] ?? null) === 'baseline') {
            return ['id' => 'baseline', 'name' => '', 'email' => '', 'baseline' => true];
        }

        $editorId = is_array($record) ? ($record['editor_id'] ?? null) : null;

        if (empty($editorId) || $editorId === 'unknown' || $editorId === 'kirby') {
            return ['id' => 'unknown', 'name' => '', 'email' => '', 'outside' => true];
        }

        $user = kirby()->user($editorId);
        if (!$user) {
            return ['id' => (string)$editorId, 'name' => '', 'email' => '', 'deleted' => true];
        }

        return [
            'id'    => $user->id(),
            'name'  => $user->name()->value(),
            'email' => $user->email(),
        ];
    }

    public function isDefaultContentFile(mixed $file): bool
    {
        if (kirby()->multilang()) {
            return (bool)preg_match(
                '/\.' . preg_quote(kirby()->defaultLanguage()->code(), '/') . '\.txt$/',
                $file->getBasename()
            );
        }

        return $file->getExtension() === 'txt';
    }

    protected function fallbackModelId(string $path): string
    {
        $id = preg_replace('%_?drafts/%', '', $path);

        return preg_replace('%\d+_%', '', $id);
    }
}
