<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Site;
use Kirby\Cms\User;
use Kirby\Http\Remote;
use Kirby\Data\Data;
use Kirby\Filesystem\F;
use Kirby\Cms\Page;

class ChangeTracker
{
    protected static array $requestTrackedEntries = [];

    public static function resetRequestTrackedEntries(): void
    {
        self::$requestTrackedEntries = [];
    }

    /** Set while a restore runs: its Kirby actions are recorded as one restore entry */
    protected static int $suspended = 0;

    public static function isSuspended(): bool
    {
        return self::$suspended > 0;
    }

    /**
     * Run Kirby actions without recording each of them
     */
    public static function withoutTracking(callable $callback): mixed
    {
        self::$suspended++;

        try {
            return $callback();
        } finally {
            self::$suspended--;
        }
    }

    /**
     * Record a change. Runs in `:after` hooks, so it must never break saving:
     * errors are logged instead of thrown.
     *
     * `$meta['languages']` lists the languages that were written; without it
     * the change is recorded for the current language.
     */
    public function trackContentChange(ModelWithContent $content, array $meta = []): void
    {
        if (self::$suspended > 0) {
            return;
        }

        $languages = $meta['languages'] ?? [null];
        unset($meta['languages']);

        foreach ($languages === [] ? [null] : $languages as $language) {
            try {
                $this->recordChange($content, $meta, $language);
            } catch (\Throwable $e) {
                HistoryStore::log('Could not record a change: ' . $e->getMessage());
            }
        }
    }

    /**
     * Record an `update` action: one entry per language whose content file
     * changed. Code like `$page->update($data, 'de')` writes a translation
     * while the request language is another one.
     */
    public function trackUpdate(ModelWithContent $new, ModelWithContent $old, array $meta = []): void
    {
        if (self::$suspended > 0) {
            return;
        }

        try {
            $meta['languages'] = self::changedLanguages($new, $old);
        } catch (\Throwable) {
            // fall back to the current language
        }

        $this->trackContentChange($new, $meta);
    }

    /**
     * Codes of the languages whose stored fields differ between two states
     * of a model; [] on single-language sites or when nothing differs
     */
    public static function changedLanguages(ModelWithContent $new, ModelWithContent $old): array
    {
        if (kirby()->multilang() !== true) {
            return [];
        }

        $changed = [];
        foreach (kirby()->languages() as $language) {
            if (self::rawContent($new, $language->code()) != self::rawContent($old, $language->code())) {
                $changed[] = $language->code();
            }
        }

        return $changed;
    }

    /**
     * Fields stored in the content file of one language, without the
     * fallback values Kirby merges in from the default language
     */
    public static function rawContent(ModelWithContent $model, ?string $language = null): array
    {
        if (method_exists($model, 'version')) {
            return $model->version('latest')->read($language ?? 'default') ?? [];
        }

        // Kirby 4
        return $model->translation($language)?->content() ?? [];
    }

    protected function recordChange(ModelWithContent $content, array $meta, ?string $languageCode = null): void
    {
        $user = kirby()->user();
        if (!$user) {
            return;
        }

        [$dirPath, $fileKey, $record] = $this->buildRecord($content, $meta, $languageCode, $user->id());

        if (empty($fileKey)) {
            return;
        }

        $coalesced = $this->storeRecord($dirPath, $fileKey, $record, $meta);

        // Notify once per change, not again for coalesced follow-ups in the same request
        if ($coalesced === false) {
            $this->notify($content, $record, $user);
        }
    }

    /**
     * Record the content on disk as a change made outside the Panel, when it
     * differs from the newest entry of its language. Content is read again
     * and compared under the history lock, so parallel requests record a
     * change once and never record a state another request already moved past.
     *
     * `$knownEntries` (the entries as last read) skips the lock when
     * nothing changed, which is the usual case. `$previousFileKey` names the
     * history of the former template after the content file was renamed
     * outside the Panel; the history moves to the new key with this entry.
     *
     * Returns whether an entry was added.
     */
    public function recordExternalChange(
        ModelWithContent $content,
        ?string $languageCode = null,
        ?array $knownEntries = null,
        ?string $previousFileKey = null
    ): bool {
        [$dirPath, $fileKey, $record] = $this->buildRecord($content, [], $languageCode, null);

        if (empty($fileKey) || !is_string($record['hash'] ?? null)) {
            return false;
        }

        $templateChanged = $previousFileKey !== null && $previousFileKey !== $fileKey;

        if ($knownEntries !== null && !$templateChanged && self::changedOutside($knownEntries, $record) === null) {
            return false;
        }

        $meta = $templateChanged ? ['previous_file_key' => $previousFileKey] : [];

        $stored = $this->storeRecord($dirPath, $fileKey, $record, $meta, function (array $entries, array &$record) use ($content, $languageCode, $templateChanged): bool {
            // the content may have changed since it was read: read it again
            [$dirPath, $fileKey, $fresh] = $this->buildRecord($content, [], $languageCode, null);
            if (!is_string($fresh['hash'] ?? null)) {
                return false;
            }

            $latest = $templateChanged
                ? self::latestEntryOfLanguage($entries, (string)($fresh['language'] ?? ''))
                : self::changedOutside($entries, $fresh);

            if ($latest === null) {
                return false;
            }

            $record           = $fresh;
            $record['source'] = 'external';

            // The file's modification time is when it was written (rsync and
            // most deploys keep the source time). Use it when it falls between
            // the previous entry and now, otherwise the time of discovery.
            $contentFile = $this->contentFilePath($content, $dirPath, $fileKey, $record['language'] ?? '');
            $mtime       = @filemtime($contentFile);
            if (is_int($mtime) && $mtime > (int)($latest['time'] ?? 0) && $mtime <= time()) {
                $record['time'] = $mtime;
            }

            return true;
        });

        return $stored !== null;
    }

    /**
     * Record the content on disk as the starting point (baseline) of every
     * given language that has no entry yet, so later changes, in the Panel
     * or outside, are compared with it and it can be restored.
     *
     * A baseline is no change: it has no user, `source: baseline`, and
     * sends no notification. It holds a snapshot with `enableRestore`,
     * otherwise only the hash. All languages are written in one go, under
     * the history lock; a language another request gave an entry in the
     * meantime is skipped.
     *
     * @param array<string|null> $languages
     * @return bool whether a baseline was added
     */
    public function recordBaselines(ModelWithContent $content, array $languages): bool
    {
        $records = [];

        foreach ($languages as $language) {
            [$dirPath, $fileKey, $record] = $this->buildRecord($content, [], $language, null);

            // nothing to compare with later, e.g. a file without metadata
            if (empty($fileKey) || !is_string($record['hash'] ?? null)) {
                continue;
            }

            $record['source'] = 'baseline';
            $records[]        = $record;
        }

        if ($records === []) {
            return false;
        }

        $added = false;

        HistoryStore::mutate($dirPath, function (array $history) use ($fileKey, $records, &$added) {
            $entries = is_array($history[$fileKey] ?? null) ? $history[$fileKey] : [];
            $version = 0;

            foreach ($entries as $entry) {
                $version = max($version, is_array($entry) ? (int)($entry['version'] ?? 1) : 0);
            }

            foreach ($records as $record) {
                if (!self::needsBaseline($entries, (string)($record['language'] ?? ''))) {
                    continue;
                }

                $record['version'] = ++$version;
                array_unshift($entries, $record);
                $added = true;
            }

            if ($added !== true) {
                return null;
            }

            $history[$fileKey] = self::applyRetention($entries);

            return $history;
        });

        return $added;
    }

    /**
     * Whether a language has no entry to compare with yet. Entries stored
     * without a language (before restore or detection was turned on) count
     * as history of unknown language: no baseline then.
     */
    public static function needsBaseline(array $entries, string $language): bool
    {
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!array_key_exists('language', $entry) || (string)$entry['language'] === $language) {
                return false;
            }
        }

        return true;
    }

    /**
     * The newest entry of the record's language when the record (the content
     * on disk) differs from what the history knows, otherwise null.
     *
     * Content is compared per language. Template, slug, status and parent
     * belong to the page, not to a language: they are compared once, in the
     * default language, against the newest entry of any language that knows
     * them, so a Panel status change recorded in one language doesn't look
     * like a change outside the Panel in the others.
     */
    public static function changedOutside(array $entries, array $record): ?array
    {
        $language = (string)($record['language'] ?? '');
        $latest   = self::latestEntryOfLanguage($entries, $language);

        if ($latest === null) {
            return null;
        }

        $differs = self::differsFromEntry($latest, $record);
        if ($differs === null) {
            return null;
        }

        if ($differs === false && self::isStructureLanguage($language)) {
            $differs = self::structureDiffers($entries, $record);
        }

        return $differs ? $latest : null;
    }

    /**
     * Newest entry of a language in a list ordered newest first
     */
    public static function latestEntryOfLanguage(array $entries, string $language): ?array
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && (string)($entry['language'] ?? '') === $language) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Whether the stored content of `$current` (a fresh record with `hash`)
     * differs from a stored entry. Null when the entry holds nothing to
     * compare with.
     */
    public static function differsFromEntry(array $entry, array $current): ?bool
    {
        $currentHash = $current['hash'] ?? null;
        if (!is_string($currentHash)) {
            return null;
        }

        if (!empty($entry['content']) && is_string($entry['content'])) {
            $snapshot  = SnapshotSerializer::split($entry['content'], $entry['meta'] ?? null);
            $entryHash = isset($entry['hash'], $entry['meta']) && is_string($entry['hash'])
                ? $entry['hash']
                : CurrentContent::hash($snapshot['content']);
        } elseif (is_string($entry['hash'] ?? null)) {
            $entryHash = $entry['hash'];
        } else {
            return null;
        }

        return $entryHash !== $currentHash;
    }

    /**
     * Whether template, slug, status or parent of `$current` differ from
     * the newest entry (any language) that stored them
     */
    protected static function structureDiffers(array $entries, array $current): bool
    {
        $currentMeta = $current['meta'] ?? null;
        if (!is_array($currentMeta)) {
            return false;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['content']) || !is_string($entry['content'])) {
                continue;
            }

            $entryMeta = SnapshotSerializer::split($entry['content'], $entry['meta'] ?? null)['meta'];
            if ($entryMeta === []) {
                continue;
            }

            foreach ($entryMeta as $key => $value) {
                if (array_key_exists($key, $currentMeta) && (string)$value !== (string)$currentMeta[$key]) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Single-language sites and the default language carry the page structure
     */
    protected static function isStructureLanguage(string $language): bool
    {
        return $language === '' || $language === kirby()->defaultLanguage()?->code();
    }

    /**
     * Location and history entry for the current state of a model
     *
     * @return array{0: string, 1: string, 2: array}
     */
    protected function buildRecord(ModelWithContent $content, array $meta, ?string $languageCode, ?string $editorId): array
    {
        $record = [
            'editor_id' => $editorId,
            'uuid'      => bin2hex(random_bytes(16)),
            'time'      => time(),
        ];

        if (!empty($meta['action']) && is_string($meta['action'])) {
            $record['action'] = $meta['action'];
        }

        foreach (['restored_from', 'restored_from_id', 'restored_from_version'] as $key) {
            if (isset($meta[$key])) {
                $record[$key] = $meta[$key];
            }
        }

        $isPage         = $content instanceof Page;
        $isSite         = $content instanceof Site;
        $detectExternal = ExternalChanges::isEnabled();

        if ($isPage || $isSite) {
            $dirPath = $content->root();
            // intendedTemplate() uses the content filename, not the fallback template
            $fileKey         = $isPage ? $content->intendedTemplate()->name() : 'site';
            $kirbyLanguage   = $languageCode !== null ? kirby()->language($languageCode) : kirby()->language();
            $record['type']  = 'page';
            $enableRestore   = option('tearoom1.kirby-content-watch.enableRestore') === true;

            if ($enableRestore || $detectExternal) {
                $language    = $kirbyLanguage ? $kirbyLanguage->code() : '';
                $fileContent = F::read($this->contentFilePath($content, $dirPath, $fileKey, $language)) ?: '';

                if ($enableRestore) {
                    $record['content'] = $fileContent;
                }

                $record['hash']     = CurrentContent::hash($fileContent);
                $record['language'] = $language;

                if ($content instanceof Page) {
                    $record['meta'] = [
                        'path'     => $content->parent()?->id() ?? '',
                        // the folder name: the slug of the default language, not a translated one
                        'slug'     => $content->slug(kirby()->defaultLanguage()?->code()),
                        'status'   => $content->status(),
                        'template' => $content->intendedTemplate()->name(),
                    ];
                }
            }
        } else {
            $dirPath         = dirname($content->root());
            $fileKey         = $content->filename();
            $record['type']  = 'file';

            // a hash of the metadata file is enough to notice changes outside the Panel
            if ($detectExternal) {
                $file = $this->contentFilePath($content, $dirPath, $fileKey, '');
                if (is_file($file)) {
                    $record['hash'] = CurrentContent::hash((string)F::read($file));
                }
            }
        }

        return [$dirPath, $fileKey, $record];
    }

    /**
     * Content file of a model in one language. Files have one history per
     * file, compared against the metadata of the default language.
     */
    protected function contentFilePath(ModelWithContent $content, string $dirPath, string $fileKey, string $language): string
    {
        if ($content instanceof File) {
            $default = kirby()->multilang() ? kirby()->defaultLanguage()?->code() : null;

            return $content->root() . ($default ? '.' . $default : '') . '.txt';
        }

        return $dirPath . '/' . $fileKey . ($language !== '' ? '.' . $language : '') . '.txt';
    }

    /**
     * Add a record to the history of a folder, prune, and write it back
     *
     * @param callable|null $guard runs under the lock with the entries of the
     *                             file key and the record (by reference);
     *                             returning false stores nothing
     * @return bool|null null when nothing was stored, true when the record
     *                   replaced the previous entry (coalesced), false when added
     */
    protected function storeRecord(string $dirPath, string $fileKey, array &$record, array $meta, ?callable $guard = null): ?bool
    {
        $editorFile = HistoryStore::path($dirPath);
        $coalesced  = null;

        HistoryStore::mutate($dirPath, function (array $history) use (&$record, &$coalesced, $meta, $fileKey, $editorFile, $guard) {
            $previousFileKey = $meta['previous_file_key'] ?? null;
            if (
                is_string($previousFileKey) &&
                $previousFileKey !== '' &&
                $previousFileKey !== $fileKey &&
                isset($history[$previousFileKey]) &&
                is_array($history[$previousFileKey])
            ) {
                $history[$fileKey] = array_values(array_merge(
                    $history[$fileKey] ?? [],
                    $history[$previousFileKey]
                ));
                unset($history[$previousFileKey]);
            }

            if (!isset($history[$fileKey]) || !is_array($history[$fileKey])) {
                $history[$fileKey] = [];
            }

            if ($guard !== null && $guard($history[$fileKey], $record) !== true) {
                return null;
            }

            // Increment version from the highest existing version number
            $latestVersion = 0;
            if (count($history[$fileKey]) > 0) {
                $latestVersion = max(array_map(
                    fn($entry) => is_array($entry) ? ($entry['version'] ?? 1) : 0,
                    $history[$fileKey]
                ));
            }
            $record['version'] = $latestVersion + 1;

            $requestKey     = $this->requestTrackedKey($editorFile, $fileKey, $record, $meta);

            $coalesced      = $this->shouldCoalesceWithinRequest($requestKey, $history[$fileKey], $record, $meta);

            if ($coalesced) {
                $record['uuid'] = $history[$fileKey][0]['uuid'] ?? $record['uuid'];
                $record['version'] = $history[$fileKey][0]['version'] ?? $record['version'];
                $history[$fileKey][0] = $record;
            } else {
                // Newest entry first
                array_unshift($history[$fileKey], $record);
                self::$requestTrackedEntries[$requestKey] = true;
            }

            $history[$fileKey] = self::applyRetention($history[$fileKey]);

            return $history;
        });

        return $coalesced;
    }

    /**
     * Send a change notification to the configured `notify` target:
     * a URL (JSON POST webhook) or a callable receiving the payload.
     */
    protected function notify(ModelWithContent $content, array $record, User $user): void
    {
        $target = option('tearoom1.kirby-content-watch.notify');
        if (empty($target)) {
            return;
        }

        $payload = [
            'event'     => 'content-watch.change',
            'type'      => $record['type'] ?? null,
            'action'    => $record['action'] ?? 'edited',
            'id'        => $content instanceof Site ? 'site' : $content->id(),
            'title'     => $content instanceof File ? $content->filename() : $content->title()->value(),
            'version'   => $record['version'],
            'language'  => $record['language'] ?? null,
            'time'      => $record['time'],
            'editor'    => [
                'id'    => $user->id(),
                'name'  => $user->name()->value(),
                'email' => $user->email(),
            ],
            'panel_url' => $content->panel()->url(),
        ];

        try {
            if ($target instanceof \Closure) {
                $target($payload);
            } elseif (is_string($target)) {
                Remote::request($target, [
                    'method'  => 'POST',
                    'headers' => ['Content-Type' => 'application/json'],
                    'data'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timeout' => 3,
                ]);
            }
        } catch (\Throwable) {
            // A failing notification must never break saving content
        }
    }

    /**
     * Prune a list of entries (newest first) by `retentionDays` and
     * `retentionCount`, per language:
     *
     * - entries within the retention window stay,
     * - of the older ones, the newest stays too: it is the state the first
     *   change in the window started from, so that change can still be
     *   compared and undone. When nothing changed within the window, it is
     *   the last known state, which detection of changes outside the Panel
     *   compares with. A baseline (starting point) is pruned like any other
     *   entry once a newer entry takes over this role.
     * - then at most `retentionCount` (at least one) entries are kept.
     */
    public static function applyRetention(array $entries, ?int $now = null): array
    {
        $retentionDays  = (int)option('tearoom1.kirby-content-watch.retentionDays', 30);
        $retentionCount = (int)option('tearoom1.kirby-content-watch.retentionCount', 10);
        $cutoffTime     = ($now ?? time()) - ($retentionDays * 86400);
        $baseKept       = [];

        $entries = array_values(array_filter($entries, function ($entry) use ($cutoffTime, &$baseKept) {
            if (!is_array($entry) || !isset($entry['time'])) {
                return false;
            }

            if ((int)$entry['time'] >= $cutoffTime) {
                return true;
            }

            // the newest entry before the window, one per language
            $language = (string)($entry['language'] ?? '');
            if (isset($baseKept[$language])) {
                return false;
            }

            return $baseKept[$language] = true;
        }));

        return self::limitPerLanguage($entries, max(1, $retentionCount));
    }

    /**
     * Keep the newest $count entries of each language, so frequent edits in
     * one language don't push the history of the others out.
     */
    public static function limitPerLanguage(array $entries, int $count): array
    {
        $kept = [];

        return array_values(array_filter($entries, function ($entry) use (&$kept, $count) {
            $language = is_array($entry) ? (string)($entry['language'] ?? '') : '';
            $kept[$language] = ($kept[$language] ?? 0) + 1;

            return $kept[$language] <= $count;
        }));
    }

    /**
     * Remove the history of a deleted file so it doesn't linger as an orphan.
     */
    public function forgetFile(File $file): void
    {
        try {
            $dirPath = dirname($file->root());
            if (!F::exists(HistoryStore::path($dirPath))) {
                return;
            }

            HistoryStore::mutate($dirPath, function (array $history) use ($file) {
                if (!array_key_exists($file->filename(), $history)) {
                    return null;
                }

                unset($history[$file->filename()]);
                return $history;
            });
        } catch (\Throwable $e) {
            HistoryStore::log('Could not forget a file: ' . $e->getMessage());
        }
    }

    /**
     * Drop the history of a page and all its descendants, e.g. after
     * duplicating, where Kirby copies the original's history files along.
     */
    public function resetHistory(Page $page): void
    {
        HistoryStore::remove($page->root());

        foreach ($page->index(true) as $child) {
            HistoryStore::remove($child->root());
        }
    }

    public function saveTheUpdatedHistory(string $editorFile, mixed $history): void
    {
        try {
            HistoryStore::write($editorFile, is_array($history) ? $history : []);
        } catch (\Throwable $e) {
            HistoryStore::log('Could not save the history: ' . $e->getMessage());
        }
    }

    protected function requestTrackedKey(string $editorFile, string $fileKey, array $record, array $meta): string
    {
        $targetKey = $meta['coalesce_key'] ?? ($editorFile . '|' . $fileKey);

        return implode('|', [
            $targetKey,
            $record['editor_id'] ?? '',
            $record['language'] ?? '',
            $record['action'] ?? 'edited',
            $meta['coalesce_group'] ?? '',
        ]);
    }

    protected function shouldCoalesceWithinRequest(string $requestKey, array $history, array $record, array $meta): bool
    {
        if (!is_string($meta['coalesce_group'] ?? null) || $meta['coalesce_group'] === '') {
            return false;
        }

        if ((self::$requestTrackedEntries[$requestKey] ?? false) !== true) {
            return false;
        }

        $latest = $history[0] ?? null;
        if (!is_array($latest)) {
            return false;
        }

        return ($latest['editor_id'] ?? null) === ($record['editor_id'] ?? null)
            && ($latest['language'] ?? '') === ($record['language'] ?? '')
            && ($latest['action'] ?? null) === ($record['action'] ?? null);
    }
}
