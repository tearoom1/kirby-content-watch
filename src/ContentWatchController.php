<?php

namespace TearoomOne\ContentWatch;

use Kirby\Data\Data;
use Kirby\Filesystem\F;

class ContentWatchController
{
    /**
     * @return array[]
     */
    public function getContentFiles(): array
    {
        $contentDir = kirby()->root('content');
        $files      = [];

        $historyFiles  = [];
        $contentFiles  = [];

        // Single pass: collect history files and eligible content files together
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($contentDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ($file->getBasename() === '.content-watch.json') {
                try {
                    $history  = Data::read($file->getPathname(), 'json') ?: [];
                    $dirPath  = dirname($file->getPathname());
                    $historyFiles[$dirPath] = $history;
                } catch (\Exception) {
                    // Skip corrupt history files
                }
                continue;
            }

            if (
                $this->isDefaultContentFile($file) &&
                !str_ends_with(dirname($file->getPathname()), '_changes')
            ) {
                $contentFiles[] = $file;
            }
        }

        foreach ($contentFiles as $file) {
            $this->processFile($file, $contentDir, $historyFiles, $files);
        }

        $files = array_values($files);

        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    public function processFile(
        mixed   $file,
        ?string $contentDir,
        array   $historyFiles,
        array   &$files
    ): void {
        $filePath     = $file->getPathname();
        $dirPath      = dirname($filePath);
        $relativePath = str_replace($contentDir . '/', '', $filePath);

        $fileId    = preg_replace('%_?drafts/%', '', dirname($relativePath));
        $fileId    = preg_replace('%\d+_%', '', $fileId);
        $pathShort = $fileId;
        $pathId    = str_replace('/', '+', $pathShort);

        // A file that has a sibling without extension is a media file (image, PDF, etc.)
        $isMediaFile = F::exists(preg_replace('%(\.[a-z]{2})?\.txt$%', '', $filePath));

        $record = null;

        if ($isMediaFile) {
            $fileKey = preg_replace('%(\.[a-z]{2})?\.txt$%', '', basename($relativePath));
            $title   = 'File: ' . $fileKey;
            $fileId  = $fileKey;
            $panelUrl = kirby()->url('panel') . '/pages/' . $pathId . '/files/' . $fileKey;
        } elseif ($fileId === '.') {
            // Site-level file
            $fileId    = 'site';
            $fileKey   = 'site';
            $pathShort = 'site';
            $title     = 'Site';
            $panelUrl  = kirby()->url('panel') . '/site';
        } else {
            $page  = kirby()->page($fileId);
            $title = 'Unknown';
            // Use intendedTemplate() so the key matches what ChangeTracker stores
            $fileKey = basename($fileId);

            if ($page) {
                $title    = $page->title()->value();
                $fileKey  = $page->intendedTemplate()->name();
                $panelUrl = $page->panel()->url();
            } else {
                $panelUrl = kirby()->url('panel') . '/pages/' . $pathId;
            }
        }

        // Resolve history for this file
        $historyEntries = $historyFiles[$dirPath][$fileKey] ?? [];

        if (!empty($historyEntries) && is_array($historyEntries) && isset($historyEntries[0])) {
            $record = $historyEntries[0];
        }

        $modified = $record !== null ? $record['time'] : $file->getMTime();
        $editor   = $this->getEditor($record);

        $historyEntriesBuilt = [];
        foreach ($historyEntries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $historyEntriesBuilt[] = [
                'editor'         => $this->getEditor($entry),
                'time'           => $entry['time'] ?? 0,
                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0),
                'has_snapshot'   => !empty($entry['content']),
                'restored_from'  => $entry['restored_from'] ?? null,
                'version'        => $entry['version'] ?? 1,
                'language'       => $entry['language'] ?? '',
            ];
        }

        $pathParts = explode('/', $relativePath);

        $files[$dirPath . '/' . $fileKey] = [
            'id'                 => $fileId,
            'uid'                => $fileKey,
            'path_short'         => $pathShort,
            'path'               => dirname($relativePath),
            'title'              => $title,
            'parent'             => end($pathParts) ?: 'root',
            'modified'           => $modified,
            'modified_formatted' => date('Y-m-d H:i:s', $modified),
            'editor'             => $editor,
            'panel_url'          => $panelUrl,
            'dir_path'           => $dirPath,
            'is_media_file'      => $isMediaFile,
            'history'            => $historyEntriesBuilt,
        ];
    }

    public function getEditor(mixed $record): array
    {
        $unknown = ['id' => 'unknown', 'name' => 'Unknown', 'email' => 'unknown'];

        if (!isset($record) || empty($record['editor_id']) || $record['editor_id'] === 'unknown') {
            return $unknown;
        }

        $user = kirby()->user($record['editor_id']);
        if (!$user) {
            return $unknown;
        }

        return [
            'id'    => $user->id(),
            'name'  => $user->name()->value(),
            'email' => $user->email(),
        ];
    }

    public function isDefaultContentFile(mixed $file): bool
    {
        if (kirby()->multilang()) {
            return (bool)preg_match(
                '/' . kirby()->defaultLanguage()->code() . '\.txt$/',
                $file->getBasename()
            );
        }

        return $file->getExtension() === 'txt';
    }
}
