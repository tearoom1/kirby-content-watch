<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\Tests\TestCase;

class ChangeTrackerTest extends TestCase
{
    /** @var string Directory for the test page */
    private string $pageDir;

    /** @var string Intended template name (derived from content filename) */
    private string $templateKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageDir = $this->contentDir . '/1_test-page';
        $this->writeContent($this->pageDir . '/article.txt', "Title: Test Page\n----\nText: Hello\n");

        // intendedTemplate() uses the content filename, so the key is always 'article'
        $this->templateKey = 'article';
    }

    // -------------------------------------------------------------------------
    // Basic recording
    // -------------------------------------------------------------------------

    public function testHistoryFileIsCreatedOnFirstTrack(): void
    {
        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $this->assertFileExists($this->pageDir . '/.content-watch.json');
    }

    public function testHistoryEntryContainsRequiredFields(): void
    {
        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $history = $this->readHistory($this->pageDir);
        $this->assertArrayHasKey($this->templateKey, $history);

        $entry = $history[$this->templateKey][0];
        $this->assertArrayHasKey('editor_id', $entry);
        $this->assertArrayHasKey('time', $entry);
        $this->assertArrayHasKey('version', $entry);
        $this->assertArrayHasKey('type', $entry);
        $this->assertSame('page', $entry['type']);
    }

    public function testVersionIncreasesOnSubsequentTracks(): void
    {
        $tracker = new ChangeTracker();
        $page    = kirby()->page('test-page');
        $tracker->trackContentChange($page);
        $tracker->trackContentChange($page);
        $tracker->trackContentChange($page);

        $history  = $this->readHistory($this->pageDir);
        $versions = array_column($history[$this->templateKey], 'version');
        sort($versions);

        $this->assertSame([1, 2, 3], $versions);
    }

    public function testNewestEntryIsFirstInArray(): void
    {
        $tracker = new ChangeTracker();
        $page    = kirby()->page('test-page');
        $tracker->trackContentChange($page);
        $tracker->trackContentChange($page);

        $history = $this->readHistory($this->pageDir);
        $this->assertGreaterThan(
            $history[$this->templateKey][1]['version'],
            $history[$this->templateKey][0]['version']
        );
    }

    public function testEditorIdIsRecorded(): void
    {
        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $history = $this->readHistory($this->pageDir);
        // impersonate('kirby') → editor_id = 'kirby'
        $this->assertSame('kirby', $history[$this->templateKey][0]['editor_id']);
    }

    // -------------------------------------------------------------------------
    // Content snapshot (enableRestore = true)
    // -------------------------------------------------------------------------

    public function testContentSnapshotSavedWhenRestoreEnabled(): void
    {
        $this->kirby = $this->makeApp([
            'options' => ['tearoom1.kirby-content-watch.enableRestore' => true],
        ]);
        $this->kirby->impersonate('kirby');

        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $history = $this->readHistory($this->pageDir);
        $this->assertNotEmpty($history[$this->templateKey][0]['content']);
    }

    public function testContentSnapshotIncludesSlugField(): void
    {
        $this->kirby = $this->makeApp([
            'options' => ['tearoom1.kirby-content-watch.enableRestore' => true],
        ]);
        $this->kirby->impersonate('kirby');

        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $history  = $this->readHistory($this->pageDir);
        $snapshot = $history[$this->templateKey][0]['content'];

        // Slug is prepended as a synthetic field so slug renames show in diffs
        $this->assertStringContainsString('Slug: test-page', $snapshot);
    }

    public function testNoContentSnapshotWhenRestoreDisabled(): void
    {
        // Default setup has enableRestore = false
        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $history = $this->readHistory($this->pageDir);
        $this->assertArrayNotHasKey('content', $history[$this->templateKey][0]);
    }

    // -------------------------------------------------------------------------
    // Retention count
    // -------------------------------------------------------------------------

    public function testRetentionCountLimitsHistory(): void
    {
        $this->kirby = $this->makeApp([
            'options' => ['tearoom1.kirby-content-watch.retentionCount' => 3],
        ]);
        $this->kirby->impersonate('kirby');

        $tracker = new ChangeTracker();
        $page    = kirby()->page('test-page');
        for ($i = 0; $i < 5; $i++) {
            $tracker->trackContentChange($page);
        }

        $history = $this->readHistory($this->pageDir);
        $this->assertCount(3, $history[$this->templateKey]);
    }

    // -------------------------------------------------------------------------
    // Retention days (regression for the $cutoffTime bug)
    // -------------------------------------------------------------------------

    public function testRetentionDaysPrunesOldEntries(): void
    {
        $outdatedTime = time() - (40 * 86400); // 40 days ago — outside 30-day window
        $this->writeHistory($this->pageDir, [
            $this->templateKey => [[
                'editor_id' => 'kirby',
                'time'      => $outdatedTime,
                'version'   => 1,
                'type'      => 'page',
            ]],
        ]);

        $this->kirby = $this->makeApp([
            'options' => ['tearoom1.kirby-content-watch.retentionDays' => 30],
        ]);
        $this->kirby->impersonate('kirby');

        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $history = $this->readHistory($this->pageDir);
        // Old entry pruned; only the new one should remain
        $this->assertCount(1, $history[$this->templateKey]);
        $this->assertGreaterThan($outdatedTime, $history[$this->templateKey][0]['time']);
    }

    public function testRetentionDaysKeepsRecentEntries(): void
    {
        $recentTime = time() - (5 * 86400); // 5 days ago — within 30-day window
        $this->writeHistory($this->pageDir, [
            $this->templateKey => [[
                'editor_id' => 'kirby',
                'time'      => $recentTime,
                'version'   => 1,
                'type'      => 'page',
            ]],
        ]);

        $this->kirby = $this->makeApp([
            'options' => ['tearoom1.kirby-content-watch.retentionDays' => 30],
        ]);
        $this->kirby->impersonate('kirby');

        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $history = $this->readHistory($this->pageDir);
        // Recent seeded entry + new entry = 2
        $this->assertCount(2, $history[$this->templateKey]);
    }

    // -------------------------------------------------------------------------
    // No user — silently does nothing
    // -------------------------------------------------------------------------

    public function testNoHistoryRecordedWhenNoUserLoggedIn(): void
    {
        // Recreate app without impersonation
        $this->kirby = $this->makeApp();
        // Do NOT call impersonate

        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));

        $this->assertFileDoesNotExist($this->pageDir . '/.content-watch.json');
    }

    // -------------------------------------------------------------------------
    // Site tracking
    // -------------------------------------------------------------------------

    public function testSiteChangesAreTracked(): void
    {
        $this->writeContent($this->contentDir . '/site.txt', "Title: My Site\n");

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        (new ChangeTracker())->trackContentChange(kirby()->site());

        $history = $this->readHistory($this->contentDir);
        $this->assertArrayHasKey('site', $history);
        $this->assertSame('page', $history['site'][0]['type']);
    }
}
