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

        $entries = ($history ?? HistoryStore::read($dirPath))[$fileKey] ?? [];
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

            if ($tracker->recordExternalChange($model, $language, $entries)) {
                $recorded = true;
            }
        }

        return $recorded;
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

        return kirby()->languages()->codes();
    }

    protected function comparable(array $entry): bool
    {
        return !empty($entry['content']) || is_string($entry['hash'] ?? null);
    }
}
