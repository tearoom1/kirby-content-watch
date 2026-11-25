<?php

namespace TearoomOne\ContentWatch;

use Throwable;

/**
 * Remembers which content folders were checked for changes outside the
 * Panel, so opening the Content Watch area only reads and hashes the
 * content files of folders that changed since.
 *
 * A folder's signature is built from name, modification time, change time
 * (ctime, which a deploy that keeps the modification time can't set back)
 * and size of its content files (`*.txt`, all languages and file metadata)
 * and of its history file. Folder renames (slug, status, sorting, parent)
 * give a new key, a renamed content file (template) a new signature.
 *
 * Times have a resolution of one second, so a folder with a file written
 * in the last two seconds is not remembered: a second write of the same
 * size within the same second would otherwise go unnoticed.
 *
 * It is a cache: it lives in Kirby's cache folder, never in the content
 * folder, so deploys and git don't carry it to another copy of the site.
 * Without it, or when it can't be written, every folder is checked.
 */
class ScanIndex
{
    protected const VERSION = 1;

    /** Current time; tests set it to remember folders written just now */
    public static ?int $clock = null;

    /** @var array<string, string> folder (relative to the content root) => signature */
    protected array $known = [];

    /** @var array<string, string> signatures of this scan */
    protected array $seen = [];

    public function __construct(protected ?string $file, protected string $contentRoot, protected string $settings)
    {
        if ($this->file === null || is_file($this->file) !== true) {
            return;
        }

        try {
            $data = json_decode((string)@file_get_contents($this->file), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }

        // another plugin version or other settings: check everything once
        if (
            is_array($data) &&
            ($data['version'] ?? null) === self::VERSION &&
            ($data['settings'] ?? null) === $this->settings &&
            is_array($data['dirs'] ?? null)
        ) {
            $this->known = $data['dirs'];
        }
    }

    /**
     * Index of the current site, in Kirby's cache folder
     */
    public static function load(): self
    {
        $contentRoot = kirby()->root('content');
        $settings    = option('tearoom1.kirby-content-watch.enableRestore') === true ? 'restore' : 'hash';
        $file        = null;

        try {
            $dir = kirby()->root('cache') . '/content-watch';
            if (is_dir($dir) || @mkdir($dir, 0775, true) || is_dir($dir)) {
                $file = $dir . '/scan-' . md5((string)realpath($contentRoot) ?: $contentRoot) . '.json';
            }
        } catch (Throwable) {
            // no cache folder: no index
        }

        return new self($file, $contentRoot, $settings);
    }

    /**
     * Whether a file belongs to the signature of its folder
     */
    public static function counts(string $basename): bool
    {
        return $basename === HistoryStore::FILENAME || (str_ends_with($basename, '.txt') && $basename[0] !== '.');
    }

    /**
     * Signature from `basename => [mtime, ctime, size]`
     *
     * @param array<string, array{0: int, 1: int, 2: int}> $stats
     */
    public static function signature(array $stats): string
    {
        ksort($stats, SORT_STRING);

        $parts = [];
        foreach ($stats as $name => [$mtime, $ctime, $size]) {
            $parts[] = $name . ':' . $mtime . ':' . $ctime . ':' . $size;
        }

        return md5(implode('|', $parts));
    }

    /**
     * Stats of the files of a folder that count, as they are on disk now
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function statsOf(string $dirPath): array
    {
        clearstatcache();

        $stats = [];
        foreach (scandir($dirPath) ?: [] as $name) {
            $path = $dirPath . '/' . $name;
            if (self::counts($name) && is_file($path)) {
                $stats[$name] = [(int)filemtime($path), (int)filectime($path), (int)filesize($path)];
            }
        }

        return $stats;
    }

    public static function signatureOf(string $dirPath): string
    {
        return self::signature(self::statsOf($dirPath));
    }

    /**
     * Whether no file was written in the last two seconds
     *
     * @param array<string, array{0: int, 1: int, 2: int}> $stats
     */
    public static function isSettled(array $stats): bool
    {
        $now = self::$clock ?? time();

        foreach ($stats as [$mtime, $ctime]) {
            if (max($mtime, $ctime) >= $now - 1) {
                return false;
            }
        }

        return true;
    }

    public function isUnchanged(string $dirPath, string $signature): bool
    {
        return ($this->known[$this->key($dirPath)] ?? null) === $signature;
    }

    public function remember(string $dirPath, string $signature): void
    {
        $this->seen[$this->key($dirPath)] = $signature;
    }

    /**
     * Write the folders of this scan; folders not seen (deleted, renamed)
     * are dropped. Never throws.
     */
    public function save(): void
    {
        if ($this->file === null || $this->seen === $this->known) {
            return;
        }

        try {
            $json = json_encode([
                'version'  => self::VERSION,
                'settings' => $this->settings,
                'dirs'     => $this->seen,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

            $temp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temp, $json) === strlen($json) && @rename($temp, $this->file)) {
                return;
            }

            @unlink($temp);
        } catch (Throwable $e) {
            HistoryStore::log('Could not write the scan index: ' . $e->getMessage());
        }
    }

    protected function key(string $dirPath): string
    {
        $root = rtrim($this->contentRoot, '/');

        return str_starts_with($dirPath, $root . '/') ? substr($dirPath, strlen($root) + 1) : ($dirPath === $root ? '' : $dirPath);
    }
}
