<?php

namespace TearoomOne\ContentWatch;

use Kirby\Filesystem\Dir;

class LockedPages
{
    public function getLockedPages(): array
    {
        // Resolve canonical path to avoid /var vs /private/var mismatches on macOS
        $contentRoot = realpath(kirby()->roots()->content()) ?: kirby()->roots()->content();

        if (version_compare(kirby()->version(), '5.0.0', '>=')) {
            return $this->getLockedPagesV5($contentRoot, []);
        }

        return $this->getLockedPagesV4($contentRoot, []);
    }

    public function getLockedPagesV4(string $contentRoot, array $lockFiles): array
    {
        foreach ($this->getLockFilesV4($contentRoot) as $file) {
            $lockFile = file_get_contents($file);
            $userId   = preg_match('/user\s*:\s*(\S+)/m', $lockFile, $matches) ? $matches[1] : null;
            $time     = preg_match('/time\s*:\s*(\S+)/m', $lockFile, $matches) ? (int)$matches[1] : 0;

            $date       = date('Y-m-d H:i:s', $time);
            $user       = kirby()->user($userId);
            $userString = $user ? (string)$user->name()->or($user->email()) : $userId;

            $fileDir = preg_replace('%' . preg_quote($contentRoot, '%') . '/|/.lock$%', '', $file);
            $fileId  = preg_replace('%_?drafts/%', '', $fileDir);
            $fileId  = preg_replace('%\d+_%', '', $fileId);

            $title = 'Unknown';
            $page  = kirby()->page($fileId);
            if ($page) {
                $title = $page->title()->value();
            }

            $lockFiles[] = [
                'id'    => $fileId,
                'title' => $title,
                'path'  => $fileDir,
                'time'  => $time,
                'date'  => $date,
                'user'  => $userString,
            ];
        }

        return $lockFiles;
    }

    /** @return string[] Absolute canonical paths to all .lock files under $dir */
    public function getLockFilesV4(string $dir, array &$results = []): array
    {
        $dir = realpath($dir) ?: $dir;

        foreach (Dir::index($dir, true) as $relativePath) {
            $path = $dir . '/' . $relativePath;
            if (is_file($path) && str_ends_with($path, '.lock')) {
                $results[] = $path;
            }
        }

        return $results;
    }

    public function getLockedPagesV5(string $contentRoot, array $lockFiles): array
    {
        foreach ($this->getLockFilesV5($contentRoot) as $file) {
            $lockFile   = file_get_contents($file);
            $userId     = preg_match('/Lock:\s*(\S+)/m', $lockFile, $matches) ? $matches[1] : null;
            $time       = (int)filemtime($file);

            $date       = date('Y-m-d H:i:s', $time);
            $user       = kirby()->user($userId);
            $userString = $user ? (string)$user->name()->or($user->email()) : $userId;

            $fileDir = preg_replace('%' . preg_quote($contentRoot, '%') . '/|/.lock$%', '', $file);
            $fileId  = preg_replace('%_?drafts/%', '', $fileDir);
            $fileId  = preg_replace('%/_changes/.*%', '', $fileId); // was incorrectly $fileDir
            $fileId  = preg_replace('%\d+_%', '', $fileId);

            $title = 'Unknown';
            $page  = kirby()->page($fileId);
            if ($page) {
                $title = $page->title()->value();
            }

            $lockFiles[] = [
                'id'    => $fileId,
                'title' => $title,
                'path'  => $fileDir,
                'time'  => $time,
                'date'  => $date,
                'user'  => $userString,
            ];
        }

        return $lockFiles;
    }

    /** @return string[] Absolute canonical paths to all files inside _changes/ directories under $dir */
    public function getLockFilesV5(string $dir, array &$results = []): array
    {
        $dir = realpath($dir) ?: $dir;

        foreach (Dir::index($dir, true) as $relativePath) {
            $path = $dir . '/' . $relativePath;
            if (is_file($path) && str_ends_with(dirname($path), '_changes')) {
                $results[] = $path;
            }
        }

        return $results;
    }
}
