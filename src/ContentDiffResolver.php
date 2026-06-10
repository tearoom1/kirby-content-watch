<?php

namespace TearoomOne\ContentWatch;

use Kirby\Data\Data;
use Kirby\Filesystem\F;
use RuntimeException;

class ContentDiffResolver
{
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

        return DiffGenerator::generate($fromContent, $toContent);
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

        $history = Data::read($historyFile, 'json') ?: [];
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
