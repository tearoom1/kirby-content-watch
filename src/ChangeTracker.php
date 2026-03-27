<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Data\Data;
use Kirby\Filesystem\F;

class ChangeTracker
{
    public function trackContentChange(ModelWithContent $content, array $meta = []): void
    {
        $user = kirby()->user();
        if (!$user) {
            return;
        }

        $record = [
            'editor_id' => $user->id(),
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
                $fileContent  = F::read($contentFile) ?? '';

                // Prepend synthetic fields so structural page changes remain visible in diffs.
                // The title lives in the content file itself and is captured automatically.
                $pathPrefix = $isPage ? 'Path: ' . $content->id() . "\n----\n" : '';
                $slugPrefix = $isPage ? 'Slug: ' . $content->slug() . "\n----\n" : '';

                $record['content']  = $pathPrefix . $slugPrefix . $fileContent;
                $record['language'] = $language;
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

        // Newest entry first
        array_unshift($history[$fileKey], $record);

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

    public function saveTheUpdatedHistory(string $editorFile, mixed $history): void
    {
        try {
            Data::write($editorFile, $history, 'json');
        } catch (\Exception) {
            // Silently fail if we can't write the file
        }
    }
}
