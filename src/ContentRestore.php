<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Filesystem\F;

class ContentRestore
{
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
        if (option('tearoom1.kirby-content-watch.enableRestore') !== true) {
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

            $languagePart = empty($entryToRestore['language']) ? '' : '.' . $entryToRestore['language'];
            $content_file = $dirPath . '/' . $fileKey .  $languagePart . '.txt';
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
                    'editor_id' => $user->id(),
                    'time' => time(),
                    'restored_from' => $timestamp,
                    'content' => $entryToRestore['content'],
                    'language' => $entryToRestore['language'],
                    'version' => $entryToRestore['version'],
                ];

                array_unshift($history[$fileKey], $record);
                $this->saveTheUpdatedHistory($editorFile, $history);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
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
