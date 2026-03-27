<?php

namespace TearoomOne\ContentWatch;

use Kirby\Data\Data;
use Kirby\Filesystem\F;

class ContentRestore
{
    /**
     * Restore content from a history snapshot.
     *
     * @param string $dirPath  Directory path where .content-watch.json lives
     * @param string $fileKey  File key in the history (template name or filename)
     * @param int    $timestamp Unix timestamp of the history entry to restore
     */
    public function restoreContent(string $dirPath, string $fileKey, int $timestamp): bool
    {
        if (option('tearoom1.kirby-content-watch.enableRestore') !== true) {
            return false;
        }

        $editorFile = $dirPath . '/.content-watch.json';

        if (!F::exists($editorFile)) {
            return false;
        }

        try {
            $history = Data::read($editorFile, 'json') ?: [];

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

            $languagePart  = empty($entryToRestore['language']) ? '' : '.' . $entryToRestore['language'];
            $contentFile   = $dirPath . '/' . $fileKey . $languagePart . '.txt';

            if (!$entryToRestore || empty($entryToRestore['content']) || empty($contentFile)) {
                return false;
            }

            F::write($contentFile, $entryToRestore['content']);

            // Record the restoration in history
            $user = kirby()->user();
            if ($user) {
                // Remove the original version (it will be re-added tagged as a restore)
                if (isset($entryToRestore['version'])) {
                    $history[$fileKey] = array_values(array_filter(
                        $history[$fileKey],
                        fn($entry) => !isset($entry['version']) || $entry['version'] !== $entryToRestore['version']
                    ));
                }

                $record = [
                    'editor_id'    => $user->id(),
                    'time'         => time(),
                    'restored_from' => $timestamp,
                    'content'      => $entryToRestore['content'],
                    'language'     => $entryToRestore['language'],
                    'version'      => $entryToRestore['version'],
                ];

                array_unshift($history[$fileKey], $record);
                $this->saveTheUpdatedHistory($editorFile, $history);
            }

            return true;
        } catch (\Exception) {
            return false;
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
}
