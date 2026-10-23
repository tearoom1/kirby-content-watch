<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\Tests\TestCase;

/**
 * Regression tests for the storage findings of review CW-REVIEW-1 (3.1 - 3.3)
 */
class HistoryStoreTest extends TestCase
{
    protected function page(string $content = "Title: Page\n"): \Kirby\Cms\Page
    {
        $this->writeContent($this->contentDir . '/page/default.txt', $content);
        $this->kirby = $this->makeApp(['options' => ['tearoom1.kirby-content-watch.enableRestore' => true]]);
        $this->kirby->impersonate('kirby');

        return $this->kirby->page('page');
    }

    /**
     * 3.3: a corrupt history file must not block saving and is kept aside
     */
    public function testCorruptHistoryIsMovedAsideAndANewHistoryStarts(): void
    {
        $page = $this->page();
        file_put_contents($this->contentDir . '/page/.content-watch.json', '{"default": [ broken');

        // must not throw
        (new ChangeTracker())->trackContentChange($page);

        $history = $this->readHistory($this->contentDir . '/page');
        $this->assertCount(1, $history['default']);

        $corrupt = glob($this->contentDir . '/page/.content-watch.corrupt-*.json');
        $this->assertCount(1, $corrupt);
        $this->assertSame('{"default": [ broken', file_get_contents($corrupt[0]));
    }

    /**
     * 3.3: invalid UTF-8 in the content must never empty the history
     */
    public function testInvalidUtf8NeverEmptiesTheHistory(): void
    {
        $page = $this->page("Title: Caf\xE9 broken\n");
        $this->writeHistory($this->contentDir . '/page', ['default' => [
            ['editor_id' => 'kirby', 'uuid' => 'older', 'time' => time() - 10, 'version' => 1, 'language' => '', 'content' => "Title: Fine\n"],
        ]]);

        (new ChangeTracker())->trackContentChange($page);

        $raw = file_get_contents($this->contentDir . '/page/.content-watch.json');
        $this->assertNotSame('', $raw);

        $history = json_decode($raw, true);
        $this->assertCount(2, $history['default']);
        $this->assertSame('older', $history['default'][1]['uuid']);
        $this->assertStringContainsString("Caf\u{FFFD} broken", $history['default'][0]['content']);
    }

    /**
     * 3.2: the file is replaced atomically (new inode), never truncated in place
     */
    public function testHistoryIsReplacedAtomically(): void
    {
        $page = $this->page();
        $tracker = new ChangeTracker();
        $tracker->trackContentChange($page);

        $file  = $this->contentDir . '/page/.content-watch.json';
        $inode = fileinode($file);
        clearstatcache();

        ChangeTracker::resetRequestTrackedEntries();
        $tracker->trackContentChange($page);
        clearstatcache();

        $this->assertNotSame($inode, fileinode($file));
        $this->assertSame([], glob($this->contentDir . '/page/.content-watch.*.tmp'));
        $this->assertCount(2, $this->readHistory($this->contentDir . '/page')['default']);
    }

    /**
     * 3.1: parallel saves keep every entry
     */
    public function testParallelWritersKeepAllEntries(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available');
        }

        $this->page();
        $dir     = $this->contentDir . '/page';
        $workers = 4;
        $writes  = 25;
        $pids    = [];

        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                for ($i = 0; $i < $writes; $i++) {
                    \TearoomOne\ContentWatch\HistoryStore::mutate($dir, function (array $history) use ($w, $i) {
                        $history['entries'][] = $w . '-' . $i;
                        usleep(100);
                        return $history;
                    });
                }
                exit(0);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $history = $this->readHistory($dir);
        $this->assertCount($workers * $writes, $history['entries']);
    }
}
