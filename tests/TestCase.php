<?php

namespace TearoomOne\ContentWatch\Tests;

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $tempDir;
    protected string $contentDir;
    protected App $kirby;

    protected function setUp(): void
    {
        // Use realpath to get the canonical path (avoids macOS /var → /private/var symlink issues)
        $this->tempDir    = realpath(sys_get_temp_dir()) . '/kirby-cw-test-' . uniqid();
        $this->contentDir = $this->tempDir . '/content';

        mkdir($this->contentDir, 0777, true);
        mkdir($this->tempDir . '/accounts', 0777, true);
        mkdir($this->tempDir . '/cache', 0777, true);
        mkdir($this->tempDir . '/sessions', 0777, true);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    /**
     * Build a fresh Kirby App instance pointing to the temp directory.
     * Pass $overrides to merge additional config (options, languages, etc.).
     */
    protected function makeApp(array $overrides = []): App
    {
        return new App(array_replace_recursive([
            'roots' => [
                'index'    => $this->tempDir,
                'content'  => $this->contentDir,
                'accounts' => $this->tempDir . '/accounts',
                'cache'    => $this->tempDir . '/cache',
                'sessions' => $this->tempDir . '/sessions',
            ],
            'options' => [
                'tearoom1.kirby-content-watch.retentionDays'     => 30,
                'tearoom1.kirby-content-watch.retentionCount'    => 10,
                'tearoom1.kirby-content-watch.enableRestore'     => false,
                'tearoom1.kirby-content-watch.enableLockedPages' => true,
            ],
        ], $overrides));
    }

    /** Recursively remove a directory and all its contents. */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** Write content to a file, creating parent directories as needed. */
    protected function writeContent(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content);
    }

    /** Write a .content-watch.json file at the given directory. */
    protected function writeHistory(string $dirPath, array $history): void
    {
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0777, true);
        }
        file_put_contents(
            $dirPath . '/.content-watch.json',
            json_encode($history, JSON_PRETTY_PRINT)
        );
    }

    /** Read and decode a .content-watch.json file. */
    protected function readHistory(string $dirPath): array
    {
        $file = $dirPath . '/.content-watch.json';
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?? [];
    }
}
