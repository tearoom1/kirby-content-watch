<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Filesystem\F;

class ChangeTracker
{
    // Helper function to track content changes
    public function trackContentChange(ModelWithContent $content)
    {
        $user = kirby()->user();
        if (!$user) return;

        $record = [
            'editor_id' => $user->id(),
            'time' => time()
        ];

        // Determine the history file path and filename key
        $isPage = $content instanceof \Kirby\Cms\Page;
        $isSite = $content instanceof \Kirby\Cms\Site;
        if ($isPage || $isSite) {
            $dirPath = $content->root();
            $fileKey = $isPage ? $content->template()->name() : 'site';
            $kirbyLanguage = kirby()->language();
            $record['type'] = 'page';

            if (option('tearoom1.kirby-content-watch.enableRestore') === true) {
                $language = $kirbyLanguage ? $kirbyLanguage->code() : '';
                $languagePart = $language !== '' ?  '.' . $language : '';
                $contentFile = $dirPath . '/' . $fileKey . $languagePart . '.txt';
                $contentSnapshot = F::read($contentFile);
                $record['content'] = $contentSnapshot;
                $record['language'] = $language;
            }
        } else {
            $dirPath = dirname($content->root());
            $fileKey = $content->filename();
            $record['type'] = 'file';
        }

        if (empty($fileKey)) return;

        // Load existing history or create empty array
        $history = [];

        $editorFile = $dirPath . '/.content-watch.json';
        if (file_exists($editorFile)) {
            try {
                $history = json_decode(file_get_contents($editorFile), true) ?: [];
            } catch (\Exception) {
                // Ignore errors, start with empty history
            }
        }

        // Initialize file history if it doesn't exist
        if (!isset($history[$fileKey]) || !is_array($history[$fileKey])) {
            $history[$fileKey] = [];
        }

        // Add version number - get the latest version number and increment
        $latestVersion = 0;
        if (count($history[$fileKey]) > 0) {
            // find the greatest version without changing the array
            $latestVersion = max(array_map(function ($entry) {
                return $entry['version'] ?? 1;
            }, $history[$fileKey]));
        }
        $record['version'] = $latestVersion + 1;

        // Get history retention period from options (default 30 days, 10 entries)
        $retentionDays = (int)option('tearoom1.kirby-content-watch.retentionDays', 30);
        $retentionCount = (int)option('tearoom1.kirby-content-watch.retentionCount', 10);
        $cutoffTime = time() - ($retentionDays * 86400); // 86400 seconds per day

        // Add new history entry to the beginning of the array
        array_unshift($history[$fileKey], $record);

        // Filter out entries older than the retention period
        $history[$fileKey] = array_filter($history[$fileKey], function ($entry) use ($retentionCount) {
            return isset($entry['time']) && $entry['time'];
        });

        // remove entries that are more than the retention count
        if (count($history[$fileKey]) > $retentionCount) {
            $history[$fileKey] = array_slice($history[$fileKey], 0, $retentionCount);
        }

        // Save the updated history
        $this->saveTheUpdatedHistory($editorFile, $history);
    }

    /**
     * @param string $editorFile
     * @param mixed $history
     * @return void
     */
    public function saveTheUpdatedHistory(string $editorFile, mixed $history): void
    {
        try {
            file_put_contents($editorFile, json_encode($history, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            // Silently fail if we can't write the file
        }
    }

}
