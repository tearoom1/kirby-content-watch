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
     * differs from the newest entry of its language. The comparison is
     * repeated under the history lock, so parallel requests record it once.
     *
     * `$knownEntries` (the entries as last read) skips the lock when
     * nothing changed, which is the usual case.
     *
     * Returns whether an entry was added.
     */
    public function recordExternalChange(ModelWithContent $content, ?string $languageCode = null, ?array $knownEntries = null): bool
    {
        [$dirPath, $fileKey, $record] = $this->buildRecord($content, [], $languageCode, null);

        if (empty($fileKey) || !is_string($record['hash'] ?? null)) {
            return false;
        }

        if ($knownEntries !== null) {
            $known = self::latestEntryOfLanguage($knownEntries, (string)($record['language'] ?? ''));
            if ($known === null || self::differsFromEntry($known, $record) !== true) {
                return false;
            }
        }

        $record['source'] = 'external';
        $contentFile      = $this->contentFilePath($content, $dirPath, $fileKey, $record['language'] ?? '');

        $stored = $this->storeRecord($dirPath, $fileKey, $record, [], function (array $entries, array &$record) use ($contentFile): bool {
            $latest = self::latestEntryOfLanguage($entries, (string)($record['language'] ?? ''));

            if ($latest === null || self::differsFromEntry($latest, $record) !== true) {
                return false;
            }

            // The file's modification time is when it was written (rsync and
            // most deploys keep the source time). Use it when it falls between
            // the previous entry and now, otherwise the time of discovery.
            $mtime = @filemtime($contentFile);
            if (is_int($mtime) && $mtime > (int)($latest['time'] ?? 0) && $mtime <= time()) {
                $record['time'] = $mtime;
            }

            return true;
        });

        return $stored !== null;
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
     * Whether the content described by `$current` (a fresh record with
     * `hash` and, for pages, `meta`) differs from a stored entry.
     * Null when the entry holds nothing to compare with.
     */
    public static function differsFromEntry(array $entry, array $current): ?bool
    {
        $currentHash = $current['hash'] ?? null;
        if (!is_string($currentHash)) {
            return null;
        }

        $entryMeta = null;

        if (!empty($entry['content']) && is_string($entry['content'])) {
            $snapshot  = SnapshotSerializer::split($entry['content'], $entry['meta'] ?? null);
            $entryMeta = $snapshot['meta'];
            $entryHash = isset($entry['hash'], $entry['meta']) && is_string($entry['hash'])
                ? $entry['hash']
                : CurrentContent::hash($snapshot['content']);
        } elseif (is_string($entry['hash'] ?? null)) {
            $entryHash = $entry['hash'];
        } else {
            return null;
        }

        if ($entryHash !== $currentHash) {
            return true;
        }

        // template, slug, status and parent count too, when both know them
        $currentMeta = $current['meta'] ?? null;
        if (is_array($entryMeta) && $entryMeta !== [] && is_array($currentMeta)) {
            foreach ($entryMeta as $key => $value) {
                if (array_key_exists($key, $currentMeta) && (string)$value !== (string)$currentMeta[$key]) {
                    return true;
                }
            }
        }

        return false;
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

            $retentionDays  = (int)option('tearoom1.kirby-content-watch.retentionDays', 30);
            $retentionCount = (int)option('tearoom1.kirby-content-watch.retentionCount', 10);
            $cutoffTime     = time() - ($retentionDays * 86400);
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

            // Prune entries older than the retention window
            $history[$fileKey] = array_values(array_filter(
                $history[$fileKey],
                fn($entry) => isset($entry['time']) && $entry['time'] >= $cutoffTime
            ));

            // Limit to retention count, per language
            $history[$fileKey] = self::limitPerLanguage($history[$fileKey], $retentionCount);

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
