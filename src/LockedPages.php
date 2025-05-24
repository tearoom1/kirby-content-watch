<?php
namespace ContentHistory;

class LockedPages
{

    public function getLockedPages(): array
    {
        $lockFiles = [];
        $contentRoot = kirby()->roots()->content();

        foreach ($this->getLockFiles($contentRoot) as $file) {

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
                'dir' => $fileDir,
                'date' => $date,
                'user' => $userString
            ];
        }
        return $lockFiles;
    }

    public function getLockFiles($dir, &$results = array())
    {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                if (preg_match('/\.lock$/', $path)) {
                    $results[] = $path;
                }
            } else if ($value != "." && $value != "..") {
                $this->getLockFiles($path, $results);
            }
        }

        return $results;
    }
}