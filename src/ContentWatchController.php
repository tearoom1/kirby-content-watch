<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;
use Kirby\Filesystem\F;

class ContentWatchController
{
    use ResolvesContentModels;

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
        $owner       = $this->findContentModelByRoot($dirPath);
        $fallbackId  = $this->fallbackModelId(dirname($relativePath));
        $fileId      = $owner instanceof Site ? 'site' : ($owner?->id() ?? $fallbackId);
        $pathShort   = $fileId;
        $pathId    = str_replace('/', '+', $pathShort);

        // A file that has a sibling without extension is a media file (image, PDF, etc.)
        $isMediaFile = F::exists(preg_replace('%(\.[a-z]{2})?\.txt$%', '', $filePath));

        $record = null;
        $page   = null;

        if ($isMediaFile) {
            $fileKey = preg_replace('%(\.[a-z]{2})?\.txt$%', '', basename($relativePath));
            $title   = 'File: ' . $fileKey;
            $fileId  = $fileKey;
            $panelUrl = $owner?->file($fileKey)?->panel()->url()
                ?? kirby()->url('panel') . '/pages/' . $pathId . '/files/' . $fileKey;
        } elseif ($owner instanceof Site) {
            $fileId    = 'site';
            $fileKey   = 'site';
            $pathShort = 'site';
            $title     = 'Site';
            $panelUrl  = $owner->panel()->url();
        } else {
            $page  = $owner instanceof Page ? $owner : null;
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
                'entry_id'       => $entry['uuid'] ?? null,
                'editor'         => $this->getEditor($entry),
                'time'           => $entry['time'] ?? 0,
                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0),
                'has_snapshot'   => !empty($entry['content']),
                'restored_from'  => $entry['restored_from'] ?? null,
                'restored_from_id' => $entry['restored_from_id'] ?? null,
                'action'         => $entry['action'] ?? null,
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
            'page_status'        => $page ? $this->pageStatus($page) : null,
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

    protected function fallbackModelId(string $path): string
    {
        $id = preg_replace('%_?drafts/%', '', $path);

        return preg_replace('%\d+_%', '', $id);
    }
}
