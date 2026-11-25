<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentDiffResolver;
use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\ContentWatchController;
use TearoomOne\ContentWatch\ExternalChanges;
use TearoomOne\ContentWatch\ScanIndex;
use TearoomOne\ContentWatch\Tests\TestCase;

/**
 * Starting points (baselines): the content as Content Watch first found it
 */
class BaselineTest extends TestCase
{
    private string $pageDir;
    private string $contentFile;

    protected function setUp(): void
    {
        parent::setUp();
        ChangeTracker::resetRequestTrackedEntries();

        $this->pageDir     = $this->contentDir . '/1_test-page';
        $this->contentFile = $this->pageDir . '/article.txt';

        $this->writeContent($this->contentDir . '/site.txt', "Title: Site\n");
        $this->writeContent($this->contentFile, "Title: Test Page\n----\nText: Hello\n");

        // files written by the test count as settled, see ScanIndex::isSettled()
        ScanIndex::$clock = time() + 10;
    }

    protected function tearDown(): void
    {
        ScanIndex::$clock = null;
        parent::tearDown();
    }

    private function app(array $options = [], array $overrides = []): void
    {
        $this->kirby = $this->makeApp(array_replace_recursive([
            'options' => array_merge([
                'tearoom1.kirby-content-watch.detectExternal' => true,
                'tearoom1.kirby-content-watch.enableRestore'  => true,
            ], $options),
        ], $overrides));
        $this->kirby->impersonate('kirby');
        ChangeTracker::resetRequestTrackedEntries();
    }

    private function multilang(array $options = []): void
    {
        $this->app($options, ['languages' => [
            ['code' => 'en', 'name' => 'English', 'default' => true],
            ['code' => 'de', 'name' => 'Deutsch'],
        ]]);
    }

    private function entries(string $key = 'article', ?string $dir = null): array
    {
        return $this->readHistory($dir ?? $this->pageDir)[$key] ?? [];
    }

    private function editOutside(string $content, ?string $file = null): void
    {
        file_put_contents($file ?? $this->contentFile, $content);
        clearstatcache();
    }

    private function scan(): array
    {
        $files = (new ContentWatchController())->getContentFiles();

        return array_values(array_filter($files, fn ($file) => $file['id'] === 'test-page'))[0] ?? [];
    }

    private function indexFile(): string
    {
        return $this->tempDir . '/cache/content-watch/scan-' . md5(realpath($this->contentDir)) . '.json';
    }

    // -------------------------------------------------------------------------
    // Baselines with detectExternal
    // -------------------------------------------------------------------------

    public function testTheAreaStoresABaselineForContentWithoutHistory(): void
    {
        $this->app();

        $page = $this->scan();

        $entries = $this->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('baseline', $entries[0]['source']);
        $this->assertNull($entries[0]['editor_id']);
        $this->assertSame(1, $entries[0]['version']);
        $this->assertSame('', $entries[0]['language']);
        $this->assertStringContainsString('Text: Hello', $entries[0]['content']);
        $this->assertSame('article', $entries[0]['meta']['template']);

        // the site gets one too
        $this->assertSame('baseline', $this->entries('site', $this->contentDir)[0]['source']);

        // shown like an entry, with the starting point as author
        $this->assertCount(1, $page['history']);
        $this->assertSame('baseline', $page['history'][0]['source']);
        $this->assertTrue($page['history'][0]['editor']['baseline']);
        $this->assertTrue($page['history'][0]['has_snapshot']);
        $this->assertTrue($page['history'][0]['same_as_current']);

        // it is no change: the page keeps its file time and no Panel user
        $this->assertSame(filemtime($this->contentFile), $page['modified']);
        $this->assertTrue($page['editor']['outside']);

        // once only
        $this->scan();
        $this->assertCount(1, $this->entries());
    }

    public function testWithoutRestoreTheBaselineHoldsTheHash(): void
    {
        $this->app(['tearoom1.kirby-content-watch.enableRestore' => false]);

        $page = $this->scan();

        $entry = $this->entries()[0];
        $this->assertSame('baseline', $entry['source']);
        $this->assertIsString($entry['hash']);
        $this->assertArrayNotHasKey('content', $entry);
        $this->assertFalse($page['history'][0]['has_snapshot']);
    }

    public function testAChangeOutsideThePanelIsComparedWithTheBaseline(): void
    {
        $this->app();
        $this->scan();

        $this->editOutside("Title: Test Page\n----\nText: Written by an agent\n");
        $page = $this->scan();

        $entries = $this->entries();
        $this->assertSame(['external', 'baseline'], array_column($entries, 'source'));
        $this->assertSame([2, 1], array_column($entries, 'version'));
        $this->assertTrue($page['editor']['outside']);
        $this->assertSame($entries[0]['time'], $page['modified']);

        $diff = (new ContentDiffResolver())->compare($this->pageDir, 'article', $entries[1]['uuid'], $entries[0]['uuid']);
        $this->assertSame(1, $diff['changed']);
        $this->assertStringContainsString('agent', $diff['html']);

        // and the starting point can be restored
        (new ContentRestore())->restore($this->pageDir, 'article', $entries[1]['uuid']);
        $this->assertStringContainsString('Text: Hello', file_get_contents($this->contentFile));
        $this->assertSame('restored', $this->entries()[0]['action']);
    }

    public function testTheHistoryFieldStoresABaseline(): void
    {
        $this->app();

        $history = (new ContentWatchController())->getModelHistory(kirby()->page('test-page'));

        $this->assertCount(1, $history);
        $this->assertSame('baseline', $history[0]['source']);
        $this->assertTrue($history[0]['editor']['baseline']);
    }

    public function testFilesGetABaselineOfTheirMetadata(): void
    {
        $this->writeContent($this->pageDir . '/photo.jpg', 'jpg');
        $this->writeContent($this->pageDir . '/photo.jpg.txt', "Alt: Before\n");
        $this->writeContent($this->pageDir . '/bare.jpg', 'jpg');
        $this->app();

        $this->scan();

        $entries = $this->entries('photo.jpg');
        $this->assertCount(1, $entries);
        $this->assertSame('baseline', $entries[0]['source']);
        $this->assertSame('file', $entries[0]['type']);
        $this->assertIsString($entries[0]['hash']);

        // a file without metadata has nothing to compare
        $this->assertArrayNotHasKey('bare.jpg', $this->readHistory($this->pageDir));

        $this->editOutside("Alt: After\n", $this->pageDir . '/photo.jpg.txt');
        $this->scan();
        $this->assertSame(['external', 'baseline'], array_column($this->entries('photo.jpg'), 'source'));
    }

    public function testEachLanguageGetsItsOwnBaseline(): void
    {
        unlink($this->contentFile);
        $this->writeContent($this->contentDir . '/site.en.txt', "Title: Site\n");
        $this->writeContent($this->pageDir . '/article.en.txt', "Title: English\n");
        $this->multilang();

        $this->scan();

        // no German translation yet: no starting point for it
        $this->assertSame(['en'], array_column($this->entries(), 'language'));

        // a translation added later gets its own
        $this->writeContent($this->pageDir . '/article.de.txt', "Title: Deutsch\n");
        clearstatcache();
        $this->scan();

        $entries = $this->entries();
        $this->assertSame(['de', 'en'], array_column($entries, 'language'));
        $this->assertSame(['baseline', 'baseline'], array_column($entries, 'source'));
        $this->assertSame([2, 1], array_column($entries, 'version'));

        // and changes are compared per language
        $this->editOutside("Title: Deutsch neu\n", $this->pageDir . '/article.de.txt');
        $this->scan();
        $this->assertSame(['external', 'baseline', 'baseline'], array_column($this->entries(), 'source'));
        $this->assertSame('de', $this->entries()[0]['language']);
    }

    public function testEntriesOfUnknownLanguageGetNoBaseline(): void
    {
        unlink($this->contentFile);
        $this->writeContent($this->pageDir . '/article.en.txt', "Title: English\n");
        $this->writeHistory($this->pageDir, ['article' => [[
            'editor_id' => 'kirby',
            'uuid'      => 'old',
            'time'      => time() - 60,
            'version'   => 1,
            'type'      => 'page',
        ]]]);
        $this->multilang();

        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));
        $this->assertCount(1, $this->entries());
    }

    public function testNoBaselineWithoutDetection(): void
    {
        $this->app(['tearoom1.kirby-content-watch.detectExternal' => false]);

        $page = $this->scan();
        (new ContentWatchController())->getModelHistory(kirby()->page('test-page'));

        $this->assertSame([], $page['history']);
        $this->assertFileDoesNotExist($this->pageDir . '/.content-watch.json');
        $this->assertFileDoesNotExist($this->indexFile());
    }

    public function testABaselineSendsNoNotification(): void
    {
        $calls = 0;
        $this->app(['tearoom1.kirby-content-watch.notify' => function () use (&$calls) {
            $calls++;
        }]);

        $this->scan();

        $this->assertCount(1, $this->entries());
        $this->assertSame(0, $calls);
    }

    // -------------------------------------------------------------------------
    // Baseline before the first Panel change (enableRestore)
    // -------------------------------------------------------------------------

    public function testTheFirstPanelChangeCanBeUndone(): void
    {
        $this->app(['tearoom1.kirby-content-watch.detectExternal' => false]);

        // page.update:before, the save, page.update:after
        $page = kirby()->page('test-page');
        (new ExternalChanges())->beforePanelChange($page);
        $updated = $page->update(['title' => 'Saved in the Panel']);
        (new ChangeTracker())->trackUpdate($updated, $page);

        $entries = $this->entries();
        $this->assertSame(['kirby', null], array_column($entries, 'editor_id'));
        $this->assertSame('baseline', $entries[1]['source']);
        $this->assertStringContainsString('Title: Test Page', $entries[1]['content']);

        // the diff shows only the Panel change
        $diff = (new ContentDiffResolver())->compare($this->pageDir, 'article', $entries[1]['uuid'], $entries[0]['uuid']);
        $this->assertSame(1, $diff['changed']);

        (new ContentRestore())->restore($this->pageDir, 'article', $entries[1]['uuid']);
        $this->assertStringContainsString('Title: Test Page', file_get_contents($this->contentFile));

        // a later change stores no further baseline
        ChangeTracker::resetRequestTrackedEntries();
        (new ExternalChanges())->beforePanelChange(kirby()->page('test-page'));
        $this->assertCount(1, array_filter($this->entries(), fn ($entry) => ($entry['source'] ?? null) === 'baseline'));
    }

    public function testTheFirstPanelChangeStoresEveryLanguage(): void
    {
        unlink($this->contentFile);
        $this->writeContent($this->pageDir . '/article.en.txt', "Title: English\n");
        $this->writeContent($this->pageDir . '/article.de.txt', "Title: Deutsch\n");
        $this->multilang(['tearoom1.kirby-content-watch.detectExternal' => false]);

        (new ExternalChanges())->beforePanelChange(kirby()->page('test-page'));

        $entries = $this->entries();
        $this->assertEqualsCanonicalizing(['en', 'de'], array_column($entries, 'language'));
        $this->assertSame(['baseline', 'baseline'], array_column($entries, 'source'));
    }

    public function testNoBaselineBeforeAPanelChangeWithoutRestore(): void
    {
        $this->app([
            'tearoom1.kirby-content-watch.detectExternal' => false,
            'tearoom1.kirby-content-watch.enableRestore'  => false,
        ]);

        (new ExternalChanges())->beforePanelChange(kirby()->page('test-page'));

        $this->assertFileDoesNotExist($this->pageDir . '/.content-watch.json');
    }

    public function testFilesGetNoBaselineBeforeAPanelChangeWithoutDetection(): void
    {
        $this->writeContent($this->pageDir . '/photo.jpg', 'jpg');
        $this->writeContent($this->pageDir . '/photo.jpg.txt', "Alt: Before\n");
        $this->app(['tearoom1.kirby-content-watch.detectExternal' => false]);

        (new ExternalChanges())->beforePanelChange(kirby()->page('test-page')->file('photo.jpg'));

        $this->assertFileDoesNotExist($this->pageDir . '/.content-watch.json');
    }

    /**
     * The plugin's own hooks, as a Panel save runs them
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginHooksStoreTheBaselineBeforeTheFirstSave(): void
    {
        require dirname(__DIR__, 2) . '/index.php';

        $this->app(['tearoom1.kirby-content-watch.detectExternal' => false]);

        kirby()->page('test-page')->update(['title' => 'First save']);
        kirby()->page('test-page')->changeStatus('unlisted');

        $this->pageDir = kirby()->page('test-page')->root();
        $this->assertSame([null, null, 'baseline'], array_map(fn ($entry) => $entry['source'] ?? null, $this->entries()));
        $this->assertSame(['kirby', 'kirby', null], array_column($this->entries(), 'editor_id'));
    }

    // -------------------------------------------------------------------------
    // Retention
    // -------------------------------------------------------------------------

    public function testAnOldBaselineStaysAsLongAsItIsTheBaseOfALaterChange(): void
    {
        $old = time() - 40 * 86400;
        $this->writeHistory($this->pageDir, ['article' => [[
            'editor_id' => null,
            'source'    => 'baseline',
            'uuid'      => 'baseline',
            'time'      => $old,
            'version'   => 1,
            'type'      => 'page',
            'language'  => '',
            'content'   => "Title: Test Page\n----\nText: Hello\n",
        ]]]);
        $this->app(['tearoom1.kirby-content-watch.retentionDays' => 30]);

        // nothing changed: the old baseline is still the last known state,
        // shown and compared with, and no second baseline is stored
        $page = $this->scan();
        $this->assertSame(['baseline'], array_column($page['history'], 'entry_id'));
        $this->assertCount(1, $this->entries());

        // a change keeps it as the state it started from
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");
        $this->scan();
        $this->assertSame(['external', 'baseline'], array_column($this->entries(), 'source'));

        // once a later entry is older than the window too, it takes over
        $history = $this->readHistory($this->pageDir);
        $history['article'][0]['time'] = $old + 86400;
        $this->writeHistory($this->pageDir, $history);
        $this->editOutside("Title: Test Page\n----\nText: Deployed again\n");
        $this->scan();

        $this->assertSame(['external', 'external'], array_column($this->entries(), 'source'));
    }

    public function testRetentionCountKeepsTheNewestEntries(): void
    {
        $this->app(['tearoom1.kirby-content-watch.retentionCount' => 2]);
        $this->scan();

        foreach (['One', 'Two more'] as $text) {
            $this->editOutside("Title: Test Page\n----\nText: $text\n");
            $this->scan();
        }

        // the baseline went, the oldest kept entry is the base now
        $this->assertSame(['external', 'external'], array_column($this->entries(), 'source'));
    }

    public function testRetentionKeepsAtLeastOneEntryPerLanguage(): void
    {
        $this->app(['tearoom1.kirby-content-watch.retentionCount' => 0]);
        $this->scan();
        $this->scan();

        $this->assertCount(1, $this->entries());
    }

    // -------------------------------------------------------------------------
    // Scan index
    // -------------------------------------------------------------------------

    public function testTheIndexSkipsFoldersUnchangedSinceTheLastScan(): void
    {
        $this->app();
        $this->scan();

        $this->assertFileExists($this->indexFile());
        $this->assertFileDoesNotExist($this->contentDir . '/.content-watch-index.json');

        // pretend the folder is unchanged although its content file differs:
        // the scan must not read it
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");
        $index = json_decode(file_get_contents($this->indexFile()), true);
        $index['dirs']['1_test-page'] = ScanIndex::signatureOf($this->pageDir);
        file_put_contents($this->indexFile(), json_encode($index));

        $page = $this->scan();
        $this->assertCount(1, $this->entries());
        // the content file was not hashed either: the stored hash was taken
        $this->assertTrue($page['history'][0]['same_as_current']);

        // without the index the change is found
        unlink($this->indexFile());
        $this->scan();
        $this->assertSame(['external', 'baseline'], array_column($this->entries(), 'source'));
    }

    public function testAChangedFolderIsCheckedAgain(): void
    {
        $this->app();
        $this->scan();

        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");
        $this->scan();
        $this->assertSame(['external', 'baseline'], array_column($this->entries(), 'source'));

        // a deleted history file is noticed too
        unlink($this->pageDir . '/.content-watch.json');
        clearstatcache();
        $this->scan();
        $this->assertSame(['baseline'], array_column($this->entries(), 'source'));
    }

    public function testTheIndexKnowsTheFolderAfterTheScanWroteItsHistory(): void
    {
        $this->app();
        $this->scan();

        $index = ScanIndex::load();
        $this->assertTrue($index->isUnchanged($this->pageDir, ScanIndex::signatureOf($this->pageDir)));
    }

    public function testAFolderWrittenJustNowIsNotRemembered(): void
    {
        ScanIndex::$clock = null;
        $this->app();
        $this->scan();

        // the scan wrote the history file this second
        $this->assertFalse(ScanIndex::load()->isUnchanged($this->pageDir, ScanIndex::signatureOf($this->pageDir)));
    }

    public function testOtherSettingsCheckEverythingAgain(): void
    {
        $this->app(['tearoom1.kirby-content-watch.enableRestore' => false]);
        $this->scan();

        $this->app();
        $this->assertFalse(ScanIndex::load()->isUnchanged($this->pageDir, ScanIndex::signatureOf($this->pageDir)));
    }
}
