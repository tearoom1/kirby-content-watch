<?php

namespace TearoomOne\ContentWatch;

use RuntimeException;
use Throwable;

/**
 * Reads and writes the `.content-watch.json` history file of a content folder
 *
 * - The whole read-modify-write cycle runs under an exclusive lock, so two
 *   saves at the same time (e.g. a page and one of its files) keep both entries.
 * - Files are written atomically (temp file + rename): readers never see a
 *   half written file.
 * - A corrupt file never blocks saving: it is moved aside as
 *   `.content-watch.corrupt-<time>.json`, logged, and the history starts fresh.
 * - Invalid UTF-8 is substituted; when encoding fails anyway nothing is written.
 */
class HistoryStore
{
    public const FILENAME = '.content-watch.json';

    public static function path(string $dirPath): string
    {
        return rtrim($dirPath, '/') . '/' . self::FILENAME;
    }

    /**
     * Current history of a folder, [] when there is none or it can't be read
     */
    public static function read(string $dirPath): array
    {
        $file = self::path($dirPath);

        if (is_file($file) !== true) {
            return [];
        }

        $data = self::decode((string)@file_get_contents($file));

        return is_array($data) ? $data : [];
    }

    /**
     * Change the history of a folder under an exclusive lock
     *
     * @param callable $change receives the history array and returns the new one,
     *                         or null to leave the file untouched
     */
    public static function mutate(string $dirPath, callable $change): array
    {
        $lock = self::lock($dirPath);

        try {
            $file    = self::path($dirPath);
            $history = [];

            if (is_file($file) === true) {
                $raw  = (string)@file_get_contents($file);
                $data = self::decode($raw);

                if ($data === null) {
                    self::quarantine($file);
                } else {
                    $history = $data;
                }
            }

            $updated = $change($history);

            if (is_array($updated) === true) {
                self::write($file, $updated);
                return $updated;
            }

            return $history;
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Remove the history file of a folder
     */
    public static function remove(string $dirPath): void
    {
        $lock = self::lock($dirPath);

        try {
            @unlink(self::path($dirPath));
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * Encode and write atomically, never write when encoding fails
     */
    public static function write(string $file, array $history): void
    {
        $json = json_encode(
            $history,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        $temp = dirname($file) . '/.content-watch.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $json) !== strlen($json)) {
            @unlink($temp);
            throw new RuntimeException('Could not write the history file ' . $file);
        }

        if (@rename($temp, $file) !== true) {
            @unlink($temp);
            throw new RuntimeException('Could not replace the history file ' . $file);
        }
    }

    /**
     * Decoded history, [] for an empty file, null when the file is corrupt
     */
    protected static function decode(string $raw): ?array
    {
        if (trim($raw) === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    protected static function quarantine(string $file): void
    {
        $target = dirname($file) . '/.content-watch.corrupt-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
        @rename($file, $target);

        self::log('Corrupt history file moved to ' . $target . ', starting a new history');
    }

    public static function log(string $message): void
    {
        error_log('[kirby-content-watch] ' . $message);
    }

    /**
     * Lock files live outside the content folder (Kirby cache root, or the
     * system temp folder), so they never show up in content, git or deploys
     *
     * @return resource|null
     */
    protected static function lock(string $dirPath)
    {
        $dir = self::lockDirectory();
        $handle = @fopen($dir . '/' . md5(realpath($dirPath) ?: $dirPath) . '.lock', 'c');

        if ($handle === false) {
            return null;
        }

        flock($handle, LOCK_EX);

        return $handle;
    }

    protected static function unlock($handle): void
    {
        if (is_resource($handle) === true) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    protected static function lockDirectory(): string
    {
        $candidates = [];

        try {
            $candidates[] = kirby()->root('cache') . '/content-watch-locks';
        } catch (Throwable) {
            // no Kirby instance, e.g. in a script
        }

        $candidates[] = sys_get_temp_dir() . '/content-watch-locks';

        foreach ($candidates as $dir) {
            if (is_dir($dir) === true || @mkdir($dir, 0775, true) === true || is_dir($dir) === true) {
                if (is_writable($dir) === true) {
                    return $dir;
                }
            }
        }

        return sys_get_temp_dir();
    }
}
