<?php

namespace TearoomOne\ContentWatch;

class ContentWatchController
{

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
            if ($file->isFile() &&
                $this->isDefaultContentFile($file) &&
                !str_ends_with(dirname($file->getPathname()), '_changes') &&
                $file->getBasename() !== '.content-watch.json'
            ) {
                $this->processFile($file, $contentDir, $historyFiles, $files);
            }
        }

        $files = array_values($files);

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
        $dirPath = dirname($filePath);
        $relativePath = str_replace($contentDir . '/', '', $filePath);

        $fileId = preg_replace('%_?drafts/%', '', dirname($relativePath));
        $fileId = preg_replace('%\\d+_%', '', $fileId);
        $pathShort = $fileId;
        $pathId = preg_replace('%/%', '+', $pathShort);

        // a file that has another file without extension is a media file
        // todo is this sufficient? we could possibly determine this based on info from the page
        $isMediaFile = file_exists(preg_replace('%(\.[a-z]{2})?\.txt$%', '', $filePath));
        if ($isMediaFile) {
            $fileKey = basename($relativePath);
            $fileKey = preg_replace('%(\.[a-z]{2})?\.txt$%', '', $fileKey);
            $title = 'File: ' . $fileKey;
            $fileId = $fileKey;
        } else if ($fileId === '.') { // site
            $fileId = 'site';
            $page = kirby()->site();

            $title = 'Site';
            $fileKey = 'site';
            $pathShort = 'site';
        } else {
            $page = kirby()->page($fileId);

            $title = 'Unknown';
            $fileKey = basename($fileId);

            if ($page) {
                $title = $page->title()->value();
                $fileKey = $page->template()->name();
            }
        }

        // Try to determine panel URL
        $pathParts = explode('/', $relativePath);

        if ($fileId === 'site') {
            $panelUrl = kirby()->url('panel') . '/site';
        } else if ($isMediaFile) {
            $panelUrl = kirby()->url('panel') . '/pages/' . $pathId . '/files/' . $fileId;
        } else {
            $panelUrl = kirby()->url('panel') . '/pages/' . $pathId;
        }

        // Get editor history for this file
        $historyEntries = [];
        if (isset($historyFiles[$dirPath]) && isset($historyFiles[$dirPath][$fileKey])) {
            $historyEntries = $historyFiles[$dirPath][$fileKey];
        }

        if (!empty($historyEntries) && is_array($historyEntries) && isset($historyEntries[0])) {
            // The latest entry is at index 0 because we used array_unshift when adding
            $record = $historyEntries[0];
        }

        $modified = isset($record) ? $record['time'] : $file->getMTime();
        $editor = $this->getEditor($record ?? null);

        $historyEntriesBuilt = [];
        // Add history entries
        foreach ($historyEntries as $entry) {
            if (!is_array($entry)) {
                continue; // Skip non-array entries
            }

            $historyEntry = [
                'editor' => $this->getEditor($entry),
                'time' => $entry['time'] ?? 0,
                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0),
                'has_snapshot' => !empty($entry['content']),
                'restored_from' => $entry['restored_from'] ?? null,
                'version' => $entry['version'] ?? 1,
                'language' => $entry['language'] ?? '',
            ];

            $historyEntriesBuilt[] = $historyEntry;
        }

        // Build file data
        $fileData = [
            'id' => $fileId,
            'uid' => $fileKey,
            'path_short' => $pathShort,
            'path' => dirname($relativePath),
            'title' => $title,
            'parent' => end($pathParts) ?: 'root',
            'modified' => $modified,
            'modified_formatted' => date('Y-m-d H:i:s', $modified),
            'editor' => $editor,
            'panel_url' => $panelUrl,
            'dir_path' => $dirPath,
            'is_media_file' => $isMediaFile,
            'history' => $historyEntriesBuilt,
        ];

        $files[$dirPath . '/' . $fileKey] = $fileData;
    }

    /**
     * @param mixed $record
     * @return array|string[]
     */
    public function getEditor(mixed $record): array
    {
        $editor = [
            'id' => 'unknown',
            'name' => 'Unknown',
            'email' => 'unknown'
        ];

        if (isset($record) && !empty($record['editor_id']) && $record['editor_id'] !== 'unknown') {
            $user = kirby()->user($record['editor_id']);
            if ($user) {
                $editor = [
                    'id' => $user->id(),
                    'name' => $user->name()->value(),
                    'email' => $user->email()
                ];
            }
        }
        return $editor;
    }

    /**
     * @param mixed $file
     * @return bool
     */
    public function isDefaultContentFile(mixed $file): bool
    {
        if (kirby()->multilang()) {
            return preg_match('/' . kirby()->defaultLanguage()->code() . '\.txt$/', $file->getBasename());
        }
        return $file->getExtension() === 'txt';
    }

}
