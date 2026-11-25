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
 * Opt-in with `tearoom1.kirby-content-watch.detectExternal`. The newest
 * entry of each language is compared with the content file on disk. A
 * language without any entry gets a baseline ("starting point") first, the
 * content as found, so the next change has something to be compared with.
 * Runs when the Content Watch area or field loads and before Kirby writes
 * a Panel change, so the outside version is kept and the Panel change gets
 * a correct diff.
 *
 * Without detection but with `enableRestore`, a page or the site still
 * gets its baseline before its first Panel change, so that change can be
 * undone (see beforePanelChange()).
 */
class ExternalChanges
{
    /** Set when a check failed, e.g. so the scan index doesn't remember the folder */
    public bool $failed = false;

    public static function isEnabled(): bool
    {
        return option('tearoom1.kirby-content-watch.detectExternal', false) === true;
    }

    /**
     * Runs in the `:before` hooks of Panel changes. Never throws.
     *
     * - With `detectExternal`: records a change made outside the Panel, or
     *   the baseline of a model without history.
     * - Otherwise, with `enableRestore`: stores the baseline of a page or
     *   the site without history, so its first change can be compared and
     *   undone. Files are not restorable, they get no baseline here.
     */
    public function beforePanelChange(ModelWithContent $model): void
    {
        if (self::isEnabled()) {
            $this->check($model);
            return;
        }

        if (
            option('tearoom1.kirby-content-watch.enableRestore') !== true ||
            $model instanceof File ||
            ChangeTracker::isSuspended()
        ) {
            return;
        }

        try {
            $this->detect($model, null, false);
        } catch (Throwable $e) {
            $this->failed = true;
            HistoryStore::log('Could not store the starting point: ' . $e->getMessage());
        }
    }

    /**
     * Compare a model with its history and record changes made outside
     * the Panel; a language without history gets its baseline. Never
     * throws: errors are logged.
     *
     * @param array|null $history the folder's history when the caller has
     *                            read it already, saves a read per model
     * @return bool whether an entry (change or baseline) was added
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
            $this->failed = true;
            HistoryStore::log('Could not check for changes outside the Panel: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param bool $compare false only adds missing baselines
     */
    protected function detect(ModelWithContent $model, ?array $history, bool $compare = true): bool
    {
        [$dirPath, $fileKey] = match (true) {
            $model instanceof Site => [$model->root(), 'site'],
            $model instanceof Page => [$model->root(), $model->intendedTemplate()->name()],
            $model instanceof File => [dirname($model->root()), $model->filename()],
            default                => [null, null],
        };

        if ($dirPath === null) {
            return false;
        }

        $history     ??= HistoryStore::read($dirPath);
        $entries       = $history[$fileKey] ?? [];
        $previousKey   = null;

        // the content file was renamed outside the Panel (template change):
        // continue the history of the former template
        if ($model instanceof Page && (!is_array($entries) || $entries === []) && $history !== []) {
            $previousKey = $this->formerTemplateKey($dirPath, $fileKey, $history);
            $entries     = $previousKey !== null ? $history[$previousKey] : [];
        }

        if (!is_array($entries)) {
            $entries = [];
        }

        $tracker  = new ChangeTracker();
        $recorded = false;
        $missing  = [];

        foreach ($this->languages($model) as $language) {
            if (ChangeTracker::needsBaseline($entries, (string)$language)) {
                // a translation that doesn't exist has no starting point
                if ($this->hasContentFile($model, $dirPath, $fileKey, (string)$language)) {
                    $missing[] = $language;
                }

                continue;
            }

            if ($compare !== true) {
                continue;
            }

            $latest = ChangeTracker::latestEntryOfLanguage($entries, (string)$language);

            // nothing to compare with: an entry stored without snapshot and
            // hash (before the option was on), or of unknown language
            if ($latest === null || !$this->comparable($latest)) {
                continue;
            }

            if ($tracker->recordExternalChange($model, $language, $entries, $previousKey)) {
                $recorded = true;

                // the history has moved to the new key with the first entry
                $previousKey = null;
            }
        }

        // baselines go to the current key, also after a template change
        if ($missing !== [] && $previousKey === null && $tracker->recordBaselines($model, $missing)) {
            $recorded = true;
        }

        return $recorded;
    }

    /**
     * Whether the content file of a language exists. Files are compared by
     * the metadata file of the default language.
     */
    protected function hasContentFile(ModelWithContent $model, string $dirPath, string $fileKey, string $language): bool
    {
        if ($model instanceof File) {
            $default = kirby()->multilang() ? kirby()->defaultLanguage()?->code() : null;

            return is_file($model->root() . ($default ? '.' . $default : '') . '.txt');
        }

        return is_file($dirPath . '/' . $fileKey . ($language !== '' ? '.' . $language : '') . '.txt');
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
