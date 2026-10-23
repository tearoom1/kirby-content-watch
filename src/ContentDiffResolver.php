<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;
use Kirby\Filesystem\F;
use RuntimeException;

class ContentDiffResolver
{
    use ResolvesContentModels;

    public function generate(
        string $dirPath,
        string $fileKey,
        ?string $fromEntryId = null,
        ?string $toEntryId = null,
        ?int $fromTimestamp = null,
        ?int $toTimestamp = null
    ): string {
        $history = $this->loadHistory($dirPath, $fileKey);

        $fromContent = $this->resolveContent($history, $fromEntryId, $fromTimestamp);
        $toContent = $this->resolveContent($history, $toEntryId, $toTimestamp);

        if ($fromContent === null || $toContent === null) {
            throw new RuntimeException('One or both versions have no content', 404);
        }

        $labels = FieldLabels::forModel($this->resolveModel($dirPath, $fileKey));

        return DiffGenerator::generate($fromContent, $toContent, $labels);
    }

    /** Entry id that stands for the content as it is now on disk */
    public const CURRENT = 'current';

    /**
     * Compare two history entries, or a history entry with the current content
     * (`toEntryId` = `current`), field by field for the diff dialog
     *
     * @return array{html: string, changed: int, unchanged: int}
     */
    public function compare(
        string $dirPath,
        string $fileKey,
        ?string $fromEntryId = null,
        ?string $toEntryId = null,
        ?int $fromTimestamp = null,
        ?int $toTimestamp = null
    ): array {
        $history   = $this->loadHistory($dirPath, $fileKey);
        $fromEntry = $this->findHistoryEntry($history, $fromEntryId, $fromTimestamp);

        $fromContent = isset($fromEntry['content'])
            ? SnapshotSerializer::compose($fromEntry['content'], $fromEntry['meta'] ?? null)
            : null;

        $toContent = $toEntryId === self::CURRENT
            ? $this->currentContent($dirPath, $fileKey, $fromEntry)
            : $this->resolveContent($history, $toEntryId, $toTimestamp);

        if ($fromContent === null || $toContent === null) {
            throw new RuntimeException('One or both versions have no content', 404);
        }

        $model = $this->resolveModel($dirPath, $fileKey);

        return DiffGenerator::compare(
            $fromContent,
            $toContent,
            FieldLabels::forModel($model),
            FieldLabels::typesForModel($model)
        );
    }

    /**
     * Content file of the page or site as it is now, in the language of the
     * compared entry, with the page meta when the entry has it too
     */
    protected function currentContent(string $dirPath, string $fileKey, ?array $entry): ?string
    {
        $dirPath  = $this->resolveContentDirectory($dirPath);
        $language = (string)($entry['language'] ?? '');

        if ($dirPath === null || ($language !== '' && preg_match('/\A[A-Za-z0-9_-]+\z/', $language) !== 1)) {
            return null;
        }

        $file = $dirPath . '/' . $fileKey . ($language !== '' ? '.' . $language : '') . '.txt';
        if (F::exists($file) !== true) {
            return null;
        }

        $content = F::read($file) ?: '';
        $owner   = $this->findContentModelByRoot($dirPath);
        $meta    = null;

        if ($owner instanceof Page && !empty($entry['meta'])) {
            $meta = [
                'path'     => $owner->parent()?->id() ?? '',
                'slug'     => $owner->slug(),
                'status'   => $owner->status(),
                'template' => $owner->intendedTemplate()->name(),
            ];
        }

        return SnapshotSerializer::compose($content, $meta);
    }

    /**
     * Page, site or file the history belongs to, for its blueprint field labels
     */
    protected function resolveModel(string $dirPath, string $fileKey): ?ModelWithContent
    {
        $owner = $this->findContentModelByRoot($dirPath);

        if ($owner instanceof Site || $owner instanceof Page) {
            $file = $owner->file($fileKey);
            if ($file !== null) {
                return $file;
            }
        }

        return $owner;
    }

    protected function loadHistory(string $dirPath, string $fileKey): array
    {
        $dirPath = $this->resolveContentDirectory($dirPath);
        if ($dirPath === null || $this->isSafeFileKey($fileKey) === false) {
            throw new RuntimeException('History file not found', 404);
        }

        $historyFile = $dirPath . '/.content-watch.json';
        if (F::exists($historyFile) !== true) {
            throw new RuntimeException('History file not found', 404);
        }

        $history = HistoryStore::read($dirPath);
        if (isset($history[$fileKey]) !== true || is_array($history[$fileKey]) !== true) {
            throw new RuntimeException('No history found for this file', 404);
        }

        return $history[$fileKey];
    }

    protected function resolveContent(
        array $history,
        ?string $entryId,
        ?int $timestamp
    ): ?string {
        $entry = $this->findHistoryEntry($history, $entryId, $timestamp);

        return isset($entry['content'])
            ? SnapshotSerializer::compose($entry['content'], $entry['meta'] ?? null)
            : null;
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
            if (($entry['time'] ?? null) === $timestamp) {
                return $entry;
            }
        }

        return null;
    }

    protected function resolveContentDirectory(string $dirPath): ?string
    {
        $realDir = realpath($dirPath);
        $contentRoot = realpath(kirby()->root('content'));

        if ($realDir === false || $contentRoot === false) {
            return null;
        }

        $contentRoot = rtrim($contentRoot, DIRECTORY_SEPARATOR);
        if ($realDir !== $contentRoot && !str_starts_with($realDir, $contentRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realDir;
    }

    protected function isSafeFileKey(string $fileKey): bool
    {
        return preg_match('/\A[A-Za-z0-9._-]+\z/', $fileKey) === 1
            && str_contains($fileKey, '..') === false;
    }
}
