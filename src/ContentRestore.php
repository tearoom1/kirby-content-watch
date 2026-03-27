<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Filesystem\F;

class ContentRestore
{
    use ResolvesContentModels;

    /**
     * Restore content from a history snapshot.
     *
     * @param string $dirPath  Directory path where .content-watch.json lives
     * @param string $fileKey  File key in the history (template name or filename)
     * @param int|null    $timestamp Unix timestamp of the history entry to restore
     */
    public function restoreContent(
        string $dirPath,
        string $fileKey,
        ?string $entryId = null,
        ?int $timestamp = null
    ): bool
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

            $entryToRestore = $this->findHistoryEntry($history[$fileKey], $entryId, $timestamp);

            if (!$entryToRestore || empty($entryToRestore['content'])) {
                return false;
            }

            $snapshot      = SnapshotSerializer::split(
                $entryToRestore['content'],
                $entryToRestore['meta'] ?? null
            );
            $snapshotMeta  = $snapshot['meta'] ?? [];
            $languagePart  = empty($entryToRestore['language']) ? '' : '.' . $entryToRestore['language'];
            $targetDirPath = $dirPath;
            $targetFileKey = $fileKey;

            $page = $this->findContentModelByRoot($dirPath);
            if ($page instanceof Page) {
                $targetDirPath = $this->restorePageStructure($page, $dirPath, $snapshotMeta);
                $targetFileKey = $snapshotMeta['template'] ?? $fileKey;
            }

            $contentFile = $targetDirPath . '/' . $targetFileKey . $languagePart . '.txt';
            if (empty($contentFile)) {
                return false;
            }

            F::write($contentFile, $snapshot['content']);

            // Record the restoration in history
            $user = kirby()->user();
            if ($user) {
                if ($targetFileKey !== $fileKey && isset($history[$fileKey]) === true) {
                    $history[$targetFileKey] = array_values(array_merge(
                        $history[$targetFileKey] ?? [],
                        $history[$fileKey]
                    ));
                    unset($history[$fileKey]);
                    $fileKey = $targetFileKey;
                }

                // Remove the original version (it will be re-added tagged as a restore)
                if (isset($entryToRestore['version'])) {
                    $history[$fileKey] = array_values(array_filter(
                        $history[$fileKey],
                        fn($entry) => !isset($entry['version']) || $entry['version'] !== $entryToRestore['version']
                    ));
                }

                $record = [
                    'editor_id'    => $user->id(),
                    'uuid'         => bin2hex(random_bytes(16)),
                    'time'         => time(),
                    'restored_from' => $timestamp,
                    'restored_from_id' => $entryToRestore['uuid'] ?? null,
                    'content'      => $snapshot['content'],
                    'language'     => $entryToRestore['language'],
                    'version'      => $entryToRestore['version'],
                ];

                if ($snapshotMeta !== []) {
                    $record['meta'] = $snapshotMeta;
                }

                array_unshift($history[$fileKey], $record);
                $this->saveTheUpdatedHistory($targetDirPath . '/.content-watch.json', $history);
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

    protected function findHistoryEntry(array $history, ?string $entryId, ?int $timestamp): ?array
    {
        if (is_string($entryId) && $entryId !== '') {
            foreach ($history as $entry) {
                if (($entry['uuid'] ?? null) === $entryId) {
                    return $entry;
                }
            }
        }

        foreach ($history as $entry) {
            if (isset($entry['time']) && $entry['time'] == $timestamp) {
                return $entry;
            }
        }

        return null;
    }

    protected function restorePageStructure(Page $page, string $dirPath, array $snapshot): string
    {
        $targetDirPath = $dirPath;
        $targetSlug    = $snapshot['slug'] ?? $page->slug();
        $targetStatus  = $snapshot['status'] ?? $page->status();
        $targetTemplate = $snapshot['template'] ?? $page->intendedTemplate()->name();

        $parentRoot = $page->parent()?->root() ?? kirby()->root('content');
        $listedNum  = $page->num() ?? 1;

        $targetDirPath = match ($targetStatus) {
            'draft' => $parentRoot . '/_drafts/' . $targetSlug,
            'listed' => $parentRoot . '/' . $listedNum . '_' . $targetSlug,
            default => $parentRoot . '/' . $targetSlug,
        };

        if ($targetDirPath !== $dirPath) {
            $targetParent = dirname($targetDirPath);
            if (is_dir($targetParent) === false) {
                mkdir($targetParent, 0777, true);
            }
            F::move($dirPath, $targetDirPath);
        }

        $this->renameTemplateFiles($targetDirPath, $page->intendedTemplate()->name(), $targetTemplate);

        return $targetDirPath;
    }

    protected function renameTemplateFiles(string $dirPath, string $currentTemplate, string $targetTemplate): void
    {
        if ($currentTemplate === $targetTemplate) {
            return;
        }

        foreach (glob($dirPath . '/' . $currentTemplate . '*.txt') ?: [] as $file) {
            $basename = basename($file);
            $suffix   = substr($basename, strlen($currentTemplate));
            F::move($file, $dirPath . '/' . $targetTemplate . $suffix);
        }
    }
}
