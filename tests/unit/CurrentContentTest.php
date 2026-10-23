<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentWatchController;
use TearoomOne\ContentWatch\CurrentContent;
use TearoomOne\ContentWatch\Tests\TestCase;

class CurrentContentTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->contentDir . '/1_tea';
        $this->writeContent($this->dir . '/article.txt', "Title: Tea now\n");
    }

    protected function meta(string $status = 'listed'): array
    {
        return ['path' => '', 'slug' => 'tea', 'status' => $status, 'template' => 'article'];
    }

    protected function history(array $entries): array
    {
        $this->writeHistory($this->dir, ['article' => $entries]);
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        return (new ContentWatchController())->getModelHistory($this->kirby->page('tea'));
    }

    public function testMarksSnapshotsEqualToTheCurrentContent(): void
    {
        $time = time();
        $built = $this->history([
            ['uuid' => 'c', 'time' => $time - 10, 'version' => 3, 'content' => "Title: Tea now\n", 'hash' => CurrentContent::hash("Title: Tea now\n"), 'meta' => $this->meta()],
            ['uuid' => 'b', 'time' => $time - 20, 'version' => 2, 'content' => "Title: Tea before\n", 'meta' => $this->meta()],
            // older entry without a stored hash: hashed from the snapshot
            ['uuid' => 'a', 'time' => $time - 30, 'version' => 1, 'content' => "Title: Tea now\n", 'meta' => $this->meta()],
        ]);

        $this->assertSame([true, false, true], array_column($built, 'same_as_current'));
    }

    public function testDifferentStatusIsNotEqual(): void
    {
        $built = $this->history([
            ['uuid' => 'a', 'time' => time() - 10, 'version' => 1, 'content' => "Title: Tea now\n", 'meta' => $this->meta('draft')],
        ]);

        $this->assertFalse($built[0]['same_as_current']);
    }

    public function testEntriesWithoutSnapshotAreNeverEqual(): void
    {
        $built = $this->history([
            ['uuid' => 'a', 'time' => time() - 10, 'version' => 1],
        ]);

        $this->assertFalse($built[0]['same_as_current']);
    }

    public function testTrackerStoresTheHash(): void
    {
        $this->kirby = $this->makeApp(['options' => ['tearoom1.kirby-content-watch.enableRestore' => true]]);
        $this->kirby->impersonate('kirby');

        (new ChangeTracker())->trackContentChange($this->kirby->page('tea'));

        $entry = $this->readHistory($this->dir)['article'][0];
        $this->assertSame(CurrentContent::hash("Title: Tea now\n"), $entry['hash']);
    }

    public function testLargeHistoriesStayFast(): void
    {
        $entries = [];
        $body    = str_repeat("Lorem ipsum dolor sit amet. ", 2000);
        for ($i = 0; $i < 500; $i++) {
            $content   = "Title: Tea $i\n----\nText: $body\n";
            $entries[] = ['uuid' => "e$i", 'time' => time() - $i, 'version' => 500 - $i, 'content' => $content, 'hash' => CurrentContent::hash($content), 'meta' => $this->meta()];
        }
        $this->writeHistory($this->dir, ['article' => $entries]);
        $this->kirby = $this->makeApp(['options' => ['tearoom1.kirby-content-watch.retentionCount' => 1000]]);
        $this->kirby->impersonate('kirby');

        $current = new CurrentContent($this->kirby->page('tea'), $this->dir, 'article');
        $start   = microtime(true);
        foreach ($entries as $entry) {
            $current->equals($entry);
        }

        $this->assertLessThan(0.5, microtime(true) - $start);
    }
}
