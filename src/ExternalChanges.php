<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Throwable;

/**
 * Notices content changed outside the Panel (deploy, rsync, FTP, git,
 * scripts that write files) and records it as a history entry without
 * a Kirby user, shown as "Outside the Panel".
 *
 * Opt-in with `tearoom1.kirby-content-watch.detectExternal`. Only models
 * that already have a history are compared: the newest entry of each
 * language against the content file on disk. Runs when the Content Watch
 * area or section loads and before Kirby writes a Panel change, so the
 * outside version is kept and the Panel change gets a correct diff.
 */
class ExternalChanges
{
    public static function isEnabled(): bool
    {
        return option('tearoom1.kirby-content-watch.detectExternal', false) === true;
    }

    /**
     * Compare a model with its history and record changes made outside
     * the Panel. Never throws: errors are logged.
     *
     * @param array|null $history the folder's history when the caller has
     *                            read it already, saves a read per model
     * @return bool whether an entry was added
     */
    public function check(ModelWithContent $model, ?array $history = null): bool
    {
        // a restore writes in several steps, it checks once before it starts
        if (!self::isEnabled() || ChangeTracker::isSuspended()) {
            return false;
        }

        try {
            return $this->detect($model, $history);
        } catch (Throwable $e) {
            HistoryStore::log('Could not check for changes outside the Panel: ' . $e->getMessage());

            return false;
        }
    }

    protected function detect(ModelWithContent $model, ?array $history): bool
    {
        [$dirPath, $fileKey] = match (true) {
            $model instanceof Site => [$model->root(), 'site'],
            $model instanceof Page => [$model->root(), $model->intendedTemplate()->name()],
            $model instanceof File => [dirname($model->root()), $model->filename()],
            default                => [null, null],
        };

        if ($dirPath === null || !is_file(HistoryStore::path($dirPath))) {
            return false;
        }

        $history     ??= HistoryStore::read($dirPath);
        $entries       = $history[$fileKey] ?? [];
        $previousKey   = null;

        // the content file was renamed outside the Panel (template change):
        // continue the history of the former template
        if ($model instanceof Page && (!is_array($entries) || $entries === [])) {
            $previousKey = $this->formerTemplateKey($dirPath, $fileKey, $history);
            $entries     = $previousKey !== null ? $history[$previousKey] : [];
        }

        if (!is_array($entries) || $entries === []) {
            return false;
        }

        $tracker  = new ChangeTracker();
        $recorded = false;

        foreach ($this->languages($model) as $language) {
            $latest = ChangeTracker::latestEntryOfLanguage($entries, (string)$language);

            // nothing to compare with: no entry of this language, or one
            // stored without snapshot and hash (before the option was on)
            if ($latest === null || !$this->comparable($latest)) {
                continue;
            }

            if ($tracker->recordExternalChange($model, $language, $entries, $previousKey)) {
                $recorded = true;

                // the history has moved to the new key with the first entry
                $previousKey = null;
            }
        }

        return $recorded;
    }

    /**
     * History key of a former page template: a key holding page entries
     * whose content file no longer exists in the folder. The newest one wins.
     */
    protected function formerTemplateKey(string $dirPath, string $fileKey, array $history): ?string
    {
        $default = kirby()->multilang() ? '.' . kirby()->defaultLanguage()?->code() : '';
        $found   = null;
        $newest  = -1;

        foreach ($history as $key => $entries) {
            $key = (string)$key;
            $first = is_array($entries) ? ($entries[0] ?? null) : null;

            if (
                $key === $fileKey ||
                !is_array($first) ||
                ($first['type'] ?? null) !== 'page' ||
                is_file($dirPath . '/' . $key . $default . '.txt')
            ) {
                continue;
            }

            if ((int)($first['time'] ?? 0) > $newest) {
                $newest = (int)($first['time'] ?? 0);
                $found  = $key;
            }
        }

        return $found;
    }

    /**
     * Languages with a history of their own. Files keep one history,
     * single-language sites store entries without a language.
     *
     * @return array<string|null>
     */
    protected function languages(ModelWithContent $model): array
    {
        if ($model instanceof File || kirby()->multilang() !== true) {
            return [$model instanceof File ? null : ''];
        }

        // the default language first: it carries the page structure and,
        // after a template change, moves the history to the new key
        $default = kirby()->defaultLanguage()?->code();
        $codes   = kirby()->languages()->codes();
        usort($codes, fn ($a, $b) => ($b === $default) <=> ($a === $default));

        return $codes;
    }

    protected function comparable(array $entry): bool
    {
        return !empty($entry['content']) || is_string($entry['hash'] ?? null);
    }
}
