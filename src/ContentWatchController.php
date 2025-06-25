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
            if ($file->isFile() && $file->getExtension() === 'txt') {
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

        if (!empty($historyEntries) && is_array($historyEntries) && isset($historyEntries[0])) {
            // The latest entry is at index 0 because we used array_unshift when adding
            $record = $historyEntries[0];
        }

        if (isset($record) && !empty($record['editor_id']) && $record['editor_id'] !== 'unknown') {
            $user = kirby()->user($record['editor_id']);
            $editor = [
                'id' => $user->id(),
                'name' => $user->name()->value(),
                'email' => $user->email()
            ];
            $modified = $record['time'];
        } else {
            $editor = [
                'id' => 'unknown',
                'name' => 'Unknown',
                'email' => 'unknown'
            ];
        }

        // Try to determine panel URL
        $pathParts = explode('/', $relativePath);

        if ($fileId === '.') {
            $fileId = 'site';
            $pathShort = 'site';
            $panelUrl = '/site';
            $title = 'Site';
        } else if ($isMediaFile) {
            $panelUrl = kirby()->url('panel') . '/pages/' . $pathId . '/files/' . $fileId;
        } else {
            $panelUrl = kirby()->url('panel') . '/pages/' . $pathId;
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
            'history' => [],
            'dir_path' => $dirPath,
            'is_media_file' => $isMediaFile,
        ];

        // Add history entries
        foreach ($historyEntries as $entry) {
            if (!is_array($entry)) {
                continue; // Skip non-array entries
            }

            $historyEntry = [
                'editor' => $editor,
                'time' => $entry['time'] ?? 0,
                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0),
                'has_snapshot' => !empty($entry['content']),
                'restored_from' => $entry['restored_from'] ?? null,
                'version' => $entry['version'] ?? 1,
                'language' => $entry['language'] ?? '',
            ];

            $fileData['history'][] = $historyEntry;
        }

        $files[$dirPath . '/' . $fileKey] = $fileData;
    }

}