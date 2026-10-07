<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
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

    public function trackContentChange(ModelWithContent $content, array $meta = []): void
    {
        $user = kirby()->user();
        if (!$user) {
            return;
        }

        $record = [
            'editor_id' => $user->id(),
            'uuid'      => bin2hex(random_bytes(16)),
            'time'      => time(),
        ];

        if (!empty($meta['action']) && is_string($meta['action'])) {
            $record['action'] = $meta['action'];
        }

        $isPage = $content instanceof \Kirby\Cms\Page;
        $isSite = $content instanceof \Kirby\Cms\Site;

        if ($isPage || $isSite) {
            $dirPath = $content->root();
            // intendedTemplate() uses the content filename, not the fallback template
            $fileKey         = $isPage ? $content->intendedTemplate()->name() : 'site';
            $kirbyLanguage   = kirby()->language();
            $record['type']  = 'page';

            if (option('tearoom1.kirby-content-watch.enableRestore') === true) {
                $language     = $kirbyLanguage ? $kirbyLanguage->code() : '';
                $languagePart = $language !== '' ? '.' . $language : '';
                $contentFile  = $dirPath . '/' . $fileKey . $languagePart . '.txt';
                $fileContent  = F::read($contentFile) ?: '';

                $record['content']  = $fileContent;
                $record['language'] = $language;

                if ($content instanceof Page) {
                    $record['meta'] = [
                        'path'     => $content->parent()?->id() ?? '',
                        'slug'     => $content->slug(),
                        'status'   => $content->status(),
                        'template' => $content->intendedTemplate()->name(),
                    ];
                }
            }
        } else {
            $dirPath         = dirname($content->root());
            $fileKey         = $content->filename();
            $record['type']  = 'file';
        }

        if (empty($fileKey)) {
            return;
        }

        $editorFile = $dirPath . '/.content-watch.json';

        $history = F::exists($editorFile)
            ? (Data::read($editorFile, 'json') ?: [])
            : [];

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

        // Increment version from the highest existing version number
        $latestVersion = 0;
        if (count($history[$fileKey]) > 0) {
            $latestVersion = max(array_map(
                fn($entry) => $entry['version'] ?? 1,
                $history[$fileKey]
            ));
        }
        $record['version'] = $latestVersion + 1;

        $retentionDays  = (int)option('tearoom1.kirby-content-watch.retentionDays', 30);
        $retentionCount = (int)option('tearoom1.kirby-content-watch.retentionCount', 10);
        $cutoffTime     = time() - ($retentionDays * 86400);
        $requestKey     = $this->requestTrackedKey($editorFile, $fileKey, $record, $meta);

        if ($this->shouldCoalesceWithinRequest($requestKey, $history[$fileKey], $record, $meta)) {
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

        // Limit to retention count
        if (count($history[$fileKey]) > $retentionCount) {
            $history[$fileKey] = array_slice($history[$fileKey], 0, $retentionCount);
        }

        $this->saveTheUpdatedHistory($editorFile, $history);
    }

    /**
     * Remove the history of a deleted file so it doesn't linger as an orphan.
     */
    public function forgetFile(File $file): void
    {
        $editorFile = dirname($file->root()) . '/.content-watch.json';
        if (!F::exists($editorFile)) {
            return;
        }

        $history = Data::read($editorFile, 'json') ?: [];
        if (!array_key_exists($file->filename(), $history)) {
            return;
        }

        unset($history[$file->filename()]);
        $this->saveTheUpdatedHistory($editorFile, $history);
    }

    /**
     * Drop the history of a page and all its descendants, e.g. after
     * duplicating, where Kirby copies the original's history files along.
     */
    public function resetHistory(Page $page): void
    {
        F::remove($page->root() . '/.content-watch.json');

        foreach ($page->index(true) as $child) {
            F::remove($child->root() . '/.content-watch.json');
        }
    }

    public function saveTheUpdatedHistory(string $editorFile, mixed $history): void
    {
        try {
            Data::write($editorFile, $history, 'json');
        } catch (\Exception) {
            // Silently fail if we can't write the file
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
