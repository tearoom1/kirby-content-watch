<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Filesystem\F;

class ContentWatchController
{
    // Helper function to track content changes
    public function trackContentChange(ModelWithContent $content)
    {
        $user = kirby()->user();
        if (!$user) return;

        $record = [
            'editor' => [
                'id' => $user->id(),
                'name' => (string)$user->name(),
                'email' => (string)$user->email(),
            ],
            'time' => time()
        ];

        $language = kirby()->language()->code();

        // Determine the history file path and filename key
        if ($content instanceof \Kirby\Cms\Page) {
            $dirPath = $content->root();
            $fileKey = $content->template()->name();
            if (option('tearoom1.content-watch.enableRestore') === true) {
                $contentFile = $dirPath . '/' . $fileKey . '.' . $language . '.txt';
                $contentSnapshot = F::read($contentFile);
                $record['content'] = $contentSnapshot;
                $record['language'] = $language;
            }
        } else {
            $dirPath = dirname($content->root());
            $fileKey = $content->filename();
        }

        if (!$fileKey) return;

        // Load existing history or create empty array
        $history = [];

        $editorFile = $dirPath . '/.content-watch.json';
        if (file_exists($editorFile)) {
            try {
                $history = json_decode(file_get_contents($editorFile), true) ?: [];

                // Convert old format (direct key => value) to new format (key => [array of entries])
                foreach ($history as $key => $value) {
                    if (is_array($value)) {
                        if (!isset($value[0]) || !is_array($value[0])) {
                            // Convert to new format
                            $history[$key] = [$value];
                        }
                    } else {
                        // Handle non-array values
                        $history[$key] = [];
                    }
                }
            } catch (\Exception $e) {
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
        $retentionDays = (int)option('tearoom1.content-watch.retentionDays', 30);
        $retentionCount = (int)option('tearoom1.content-watch.retentionCount', 10);
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
        try {
            file_put_contents($editorFile, json_encode($history));
        } catch (\Exception $e) {
            // Silently fail if we can't write the file
        }
    }

    /**
     * Restore content from a history snapshot
     *
     * @param string $dirPath Directory path where .content-watch.json is located
     * @param string $fileKey File key in the history
     * @param int $timestamp Timestamp of the history entry to restore
     * @return bool True if restored successfully, false otherwise
     */
    public function restoreContent(string $dirPath, string $fileKey, int $timestamp): bool
    {
        // Check if restore functionality is enabled
        if (option('tearoom1.content-watch.enableRestore') !== true) {
            return false;
        }

        $editorFile = $dirPath . '/.content-watch.json';

        if (!file_exists($editorFile)) {
            return false;
        }

        try {
            $history = json_decode(file_get_contents($editorFile), true) ?: [];

            if (!isset($history[$fileKey]) || !is_array($history[$fileKey])) {
                return false;
            }

            // Find the entry with the matching timestamp
            $entryToRestore = null;
            foreach ($history[$fileKey] as $entry) {
                if (isset($entry['time']) && $entry['time'] == $timestamp) {
                    $entryToRestore = $entry;
                    break;
                }
            }

            $content_file = $dirPath . '/' . $fileKey . '.' . $entryToRestore['language'] . '.txt';
            if (!$entryToRestore || empty($entryToRestore['content']) || empty($content_file)) {
                return false;
            }

            // Restore the content
            F::write($content_file, $entryToRestore['content']);

            // Add restoration note to history
            $user = kirby()->user();
            if ($user) {

                // remove that version
                if (isset($entryToRestore['version'])) {
                    $history[$fileKey] = array_filter($history[$fileKey], function ($entry) use ($entryToRestore) {
                        return !isset($entry['version']) || $entry['version'] !== $entryToRestore['version'];
                    });
                }

                $record = [
                    'editor' => [
                        'id' => $user->id(),
                        'name' => (string)$user->name(),
                        'email' => (string)$user->email(),
                    ],
                    'time' => time(),
                    'restored_from' => $timestamp,
                    'content' => $entryToRestore['content'],
                    'language' => $entryToRestore['language'],
                    'version' => $entryToRestore['version'],
                ];

                array_unshift($history[$fileKey], $record);
                file_put_contents($editorFile, json_encode($history));
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return array[]
     */
    public function getContentFiles(): array
    {
        $contentDir = kirby()->root('content');
        $files = [];

        // Recursively find all content files
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($contentDir)
        );

        // First collect all history files
        $historyFiles = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getBasename() === '.content-watch.json') {
                try {
                    $history = json_decode(file_get_contents($file->getPathname()), true) ?: [];
                    $dirPath = dirname($file->getPathname());
                    $historyFiles[$dirPath] = $history;
                } catch (\Exception $e) {
                    // Skip invalid history files
                }
            }
        }

        // Process all content files
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'txt') {
                $this->processFile($file, $contentDir, $historyFiles, $files);
            }
        }

        // Sort by modification date (newest first)
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * @param mixed $file
     * @param string|null $contentDir
     * @param array $historyFiles
     * @param array $files
     * @param array $allHistoryEntries
     */
    public function processFile(mixed   $file,
                                ?string $contentDir,
                                array   $historyFiles,
                                array   &$files): void
    {
        $filePath = $file->getPathname();
        $isMediaFile = file_exists(preg_replace('%(\.[a-z]{2})?\.txt$%', '', $filePath));
        $relativePath = str_replace($contentDir . '/', '', $filePath);
        $modified = $file->getMTime();

        // Get directory and basename
        $dirPath = dirname($filePath);

        $fileId = preg_replace('%_?drafts/%', '', dirname($relativePath));
        $fileId = preg_replace('%\\d+_%', '', $fileId);
        $pathShort = $fileId;
        $pathId = preg_replace('%/%', '+', $pathShort);

        if ($isMediaFile) {
            $fileKey = basename($relativePath);
            $fileKey = preg_replace('%(\.[a-z]{2})?\.txt$%', '', $fileKey);
            $title = 'File: ' . $fileKey;
            $fileId = $fileKey;
        } else {
            $page = kirby()->page($fileId);

            $title = 'Unknown';
            $fileKey = basename($fileId);

            if ($page) {
                $title = $page->title()->value();
                $fileKey = $page->template()->name();
            }
        }

        // Get editor history for this file
        $historyEntries = [];
        if (isset($historyFiles[$dirPath]) && isset($historyFiles[$dirPath][$fileKey])) {
            $historyEntries = $historyFiles[$dirPath][$fileKey];
        }

        // Use latest history entry for file display
        $record = [
            'editor' => array(
                'id' => 'unknown',
                'name' => 'Unknown',
                'email' => '',
            ),
            'time' => $modified
        ];

        if (!empty($historyEntries) && is_array($historyEntries) && isset($historyEntries[0])) {
            // The latest entry is at index 0 because we used array_unshift when adding
            $record = $historyEntries[0];
        }

        // Try to determine panel URL
        $pathParts = explode('/', $relativePath);

        if ($fileId === '.') {
            $fileId = 'site';
            $pathShort = 'site';
            $panelUrl = '/site';
            $title = 'Site';
        } else {
            $panelUrl = '/pages/' . $pathId;
        }

        // Build file data
        $fileData = [
            'id' => $fileId,
            'uid' => $fileKey,
            'path_short' => $pathShort,
            'path' => dirname($relativePath),
            'title' => $title,
            'parent' => end($pathParts) ?: 'root',
            'modified' => $record['time'] ?? $modified,
            'modified_formatted' => date('Y-m-d H:i:s', $record['time'] ?? $modified),
            'editor' => $record['editor'],
            'panel_url' => $panelUrl,
            'history' => [],
            'dir_path' => $dirPath,
            'is_media_file' => $isMediaFile
        ];

        // Add history entries
        foreach ($historyEntries as $entry) {
            if (!is_array($entry)) {
                continue; // Skip non-array entries
            }

            $historyEntry = [
                'editor' => $record['editor'],
                'time' => $entry['time'] ?? 0,
                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0),
                'has_snapshot' => !empty($entry['content']),
                'restored_from' => $entry['restored_from'] ?? null,
                'version' => $entry['version'] ?? 1
            ];

            $fileData['history'][] = $historyEntry;
        }

        $files[] = $fileData;
    }

    public function getLockedPages(): array
    {
        $lockFiles = [];
        $contentRoot = kirby()->roots()->content();

        foreach ($this->getLockFiles($contentRoot) as $file) {

            $lockFile = file_get_contents($file);
            $userId = preg_match('/user\s*:\s*(\S+)/m', $lockFile, $matches) ? $matches[1] : null;
            $time = preg_match('/time\s*:\s*(\S+)/m', $lockFile, $matches) ? $matches[1] : null;

            // convert unix time to human readable time
            $date = date('Y-m-d H:i:s', $time);

            // get the users name or email, or id as fallback
            $user = kirby()->user($userId);
            $userString = $user ? '' . $user->name()->or($user->email()) : $userId;

            // remove the content root, order numbers and the lock file extension
            $fileDir = preg_replace('%' . $contentRoot . '/|/.lock$%', '', $file);
            $fileId = preg_replace('%_?drafts/%', '', $fileDir);
            $fileId = preg_replace('%\d+_%', '', $fileId);

            $title = 'Unknown';
            $page = kirby()->page($fileId);
            if ($page) {
                $title = $page->title()->value();
            }

            $lockFiles[] = [
                'id' => $fileId,
                'title' => $title,
                'dir' => $fileDir,
                'date' => $date,
                'user' => $userString
            ];
        }
        return $lockFiles;
    }

    public function getLockFiles($dir, &$results = array())
    {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                if (preg_match('/\.lock$/', $path)) {
                    $results[] = $path;
                }
            } else if ($value != "." && $value != "..") {
                $this->getLockFiles($path, $results);
            }
        }

        return $results;
    }
}