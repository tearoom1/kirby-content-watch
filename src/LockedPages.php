<?php

namespace TearoomOne\ContentWatch;

class LockedPages
{

    public function getLockedPages(): array
    {
        $lockFiles = [];
        $contentRoot = kirby()->roots()->content();

        if (str_starts_with(kirby()->version() , '5')) {
            return $this->getLockedPagesV5($contentRoot, $lockFiles);
        }
        return $this->getLockedPagesV4($contentRoot, $lockFiles);
    }

    /**
     * @param $contentRoot
     * @param array $lockFiles
     * @return array
     */
    public function getLockedPagesV4($contentRoot, array $lockFiles): array
    {
        foreach ($this->getLockFilesV4($contentRoot) as $file) {

            $lockFile = file_get_contents($file);
            $userId = preg_match('/user\s*:\s*(\S+)/m', $lockFile, $matches) ? $matches[1] : null;
            $time = preg_match('/time\s*:\s*(\S+)/m', $lockFile, $matches) ? $matches[1] : null;

            // convert unix time to human readable time
            $date = date('Y-m-d H:i:s', $time);

            // get the users name or email, or id as fallback
            $user = kirby()->user($userId);
            $userString = $user ? '' . $user->name()->or($user->email()) : $userId;

            // remove the content root, order numbers and the lock file extension
            $fileDir = preg_replace('%' . $contentRoot . '/|/.lock$%', '', $file);
            $fileId = preg_replace('%_?drafts/%', '', $fileDir);
            $fileId = preg_replace('%\d+_%', '', $fileId);

            $title = 'Unknown';
            $page = kirby()->page($fileId);
            if ($page) {
                $title = $page->title()->value();
            }

            $lockFiles[] = [
                'id' => $fileId,
                'title' => $title,
                'path' => $fileDir,
                'time' => (int)$time,
                'date' => $date,
                'user' => $userString
            ];
        }
        return $lockFiles;
    }

    public function getLockFilesV4($dir, &$results = array())
    {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                if (preg_match('/\.lock$/', $path)) {
                    $results[] = $path;
                }
            } else if ($value != "." && $value != "..") {
                $this->getLockFilesV4($path, $results);
            }
        }

        return $results;
    }

    /**
     * @param $contentRoot
     * @param array $lockFiles
     * @return array
     */
    public function getLockedPagesV5($contentRoot, array $lockFiles): array
    {
        foreach ($this->getLockFilesV5($contentRoot) as $file) {

            $lockFile = file_get_contents($file);
            $userId = preg_match('/Lock:\s*(\S+)/m', $lockFile, $matches) ? $matches[1] : null;
            $time = filemtime($file);

            // convert unix time to human readable time
            $date = date('Y-m-d H:i:s', $time);

            // get the users name or email, or id as fallback
            $user = kirby()->user($userId);
            $userString = $user ? '' . $user->name()->or($user->email()) : $userId;

            // remove the content root, order numbers and the lock file extension
            $fileDir = preg_replace('%' . $contentRoot . '/|/.lock$%', '', $file);
            $fileId = preg_replace('%_?drafts/%', '', $fileDir);
            $fileId = preg_replace('%/_changes/.*%', '', $fileDir);
            $fileId = preg_replace('%\d+_%', '', $fileId);

            $title = 'Unknown';
            $page = kirby()->page($fileId);
            if ($page) {
                $title = $page->title()->value();
            }

            $lockFiles[] = [
                'id' => $fileId,
                'title' => $title,
                'path' => $fileDir,
                'time' => (int)$time,
                'date' => $date,
                'user' => $userString
            ];
        }
        return $lockFiles;
    }

    public function getLockFilesV5($dir, &$results = array())
    {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                if (str_ends_with(dirname($path), '_changes')) {
                    $results[] = $path;
                }
            } else if ($value != "." && $value != "..") {
                $this->getLockFilesV5($path, $results);
            }
        }

        return $results;
    }
}