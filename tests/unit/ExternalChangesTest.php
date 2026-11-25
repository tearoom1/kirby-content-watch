<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentDiffResolver;
use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\ContentWatchController;
use TearoomOne\ContentWatch\ExternalChanges;
use TearoomOne\ContentWatch\HistoryStore;
use TearoomOne\ContentWatch\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Kirby\Cms\ModelWithContent;

class ExternalChangesTest extends TestCase
{
    private string $pageDir;
    private string $contentFile;

    protected function setUp(): void
    {
        parent::setUp();
        ChangeTracker::resetRequestTrackedEntries();

        $this->pageDir     = $this->contentDir . '/1_test-page';
        $this->contentFile = $this->pageDir . '/article.txt';

        $this->writeContent($this->contentFile, "Title: Test Page\n----\nText: Hello\n");
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

    /** A Panel save as the plugin's hooks record it */
    private function panelEntry(): void
    {
        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'));
    }

    /** A change written straight to the file, like a deploy does */
    private function editOutside(string $content, ?string $file = null): void
    {
        file_put_contents($file ?? $this->contentFile, $content);
        clearstatcache();
    }

    private function entries(string $key = 'article'): array
    {
        return $this->readHistory($this->pageDir)[$key] ?? [];
    }

    // -------------------------------------------------------------------------
    // Option off
    // -------------------------------------------------------------------------

    public function testOffRecordsNothing(): void
    {
        $this->app(['tearoom1.kirby-content-watch.detectExternal' => false]);
        $this->panelEntry();
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));
        (new ContentWatchController())->getModelHistory(kirby()->page('test-page'));

        $this->assertCount(1, $this->entries());
    }

    public function testOffStoresNoExtraFieldsWithoutRestore(): void
    {
        $this->app([
            'tearoom1.kirby-content-watch.detectExternal' => false,
            'tearoom1.kirby-content-watch.enableRestore'  => false,
        ]);
        $this->panelEntry();

        $entry = $this->entries()[0];
        $this->assertArrayNotHasKey('hash', $entry);
        $this->assertArrayNotHasKey('language', $entry);
    }

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    public function testRecordsAnOutsideChangeOnce(): void
    {
        $this->app();
        $this->panelEntry();
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

        $this->assertTrue((new ExternalChanges())->check(kirby()->page('test-page')));
        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));
        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));

        $entries = $this->entries();
        $this->assertCount(2, $entries);
        $this->assertNull($entries[0]['editor_id']);
        $this->assertSame('external', $entries[0]['source']);
        $this->assertSame(2, $entries[0]['version']);
        $this->assertStringContainsString('Deployed', $entries[0]['content']);

        $editor = (new ContentWatchController())->getEditor($entries[0]);
        $this->assertTrue($editor['outside']);
    }

    public function testUnchangedContentRecordsNothing(): void
    {
        $this->app();
        $this->panelEntry();

        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));
        $this->assertCount(1, $this->entries());
    }

    public function testModelsWithoutHistoryAreLeftAlone(): void
    {
        $this->app();

        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));
        $this->assertFileDoesNotExist($this->pageDir . '/.content-watch.json');
    }

    public function testEntriesWithoutSnapshotOrHashAreNotCompared(): void
    {
        $this->writeHistory($this->pageDir, ['article' => [[
            'editor_id' => 'kirby',
            'uuid'      => 'old',
            'time'      => time() - 60,
            'version'   => 1,
            'type'      => 'page',
        ]]]);
        $this->app();

        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));
        $this->assertCount(1, $this->entries());
    }

    public function testWorksWithoutRestoreThroughTheHash(): void
    {
        $this->app(['tearoom1.kirby-content-watch.enableRestore' => false]);
        $this->panelEntry();
        $this->assertIsString($this->entries()[0]['hash']);

        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

        $this->assertTrue((new ExternalChanges())->check(kirby()->page('test-page')));
        $this->assertFalse((new ExternalChanges())->check(kirby()->page('test-page')));

        $entries = $this->entries();
        $this->assertCount(2, $entries);
        $this->assertArrayNotHasKey('content', $entries[0]);
    }

    public function testUsesTheFileTimeWhenItIsAfterThePreviousEntry(): void
    {
        $this->writeHistory($this->pageDir, ['article' => [[
            'editor_id' => 'kirby',
            'uuid'      => 'panel',
            'time'      => time() - 3600,
            'version'   => 1,
            'type'      => 'page',
            'language'  => '',
            'content'   => "Title: Test Page\n----\nText: Before\n",
        ]]]);
        $this->app();

        touch($this->contentFile, time() - 1800);
        clearstatcache();

        (new ExternalChanges())->check(kirby()->page('test-page'));

        $this->assertSame(time() - 1800, $this->entries()[0]['time']);
    }

    public function testUsesTheTimeOfDiscoveryWhenTheFileTimeIsOlder(): void
    {
        $this->writeHistory($this->pageDir, ['article' => [[
            'editor_id' => 'kirby',
            'uuid'      => 'panel',
            'time'      => time() - 60,
            'version'   => 1,
            'type'      => 'page',
            'language'  => '',
            'content'   => "Title: Test Page\n----\nText: Before\n",
        ]]]);
        $this->app();

        // rsync keeps the time of the local edit, which can be older
        touch($this->contentFile, time() - 7200);
        clearstatcache();

        $before = time();
        (new ExternalChanges())->check(kirby()->page('test-page'));

        $this->assertGreaterThanOrEqual($before, $this->entries()[0]['time']);
    }

    public function testDetectsEachLanguageOnItsOwn(): void
    {
        $this->writeContent($this->pageDir . '/article.en.txt', "Title: English\n");
        $this->writeContent($this->pageDir . '/article.de.txt', "Title: Deutsch\n");
        $this->app([], ['languages' => [
            ['code' => 'en', 'name' => 'English', 'default' => true],
            ['code' => 'de', 'name' => 'Deutsch'],
        ]]);

        $tracker = new ChangeTracker();
        $tracker->trackContentChange(kirby()->page('test-page'), ['languages' => ['en', 'de']]);

        $this->editOutside("Title: Deutsch neu\n", $this->pageDir . '/article.de.txt');

        $this->assertTrue((new ExternalChanges())->check(kirby()->page('test-page')));

        $entries = $this->entries();
        $this->assertCount(3, $entries);
        $this->assertSame('de', $entries[0]['language']);
        $this->assertNull($entries[0]['editor_id']);
    }

    public function testDetectsChangedFileMetadata(): void
    {
        $this->writeContent($this->pageDir . '/photo.jpg', 'jpg');
        $this->writeContent($this->pageDir . '/photo.jpg.txt', "Alt: Before\n");
        $this->app();

        $file = kirby()->page('test-page')->file('photo.jpg');
        (new ChangeTracker())->trackContentChange($file);

        $this->editOutside("Alt: After\n", $this->pageDir . '/photo.jpg.txt');

        $this->assertTrue((new ExternalChanges())->check($file));
        $this->assertFalse((new ExternalChanges())->check($file));

        $entries = $this->entries('photo.jpg');
        $this->assertCount(2, $entries);
        $this->assertSame('file', $entries[0]['type']);
        $this->assertNull($entries[0]['editor_id']);
    }

    // -------------------------------------------------------------------------
    // Where it runs
    // -------------------------------------------------------------------------

    public function testSectionHistoryChecksFirst(): void
    {
        $this->app();
        $this->panelEntry();
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

        $history = (new ContentWatchController())->getModelHistory(kirby()->page('test-page'));
        (new ContentWatchController())->getModelHistory(kirby()->page('test-page'));

        $this->assertCount(2, $history);
        $this->assertTrue($history[0]['editor']['outside']);
        $this->assertTrue($history[0]['same_as_current']);
        $this->assertCount(2, $this->entries());
    }

    public function testAreaListChecksFirst(): void
    {
        $this->app();
        $this->panelEntry();
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

        $files = (new ContentWatchController())->getContentFiles();
        (new ContentWatchController())->getContentFiles();

        $page = array_values(array_filter($files, fn ($file) => $file['id'] === 'test-page'))[0];
        $this->assertCount(2, $page['history']);
        $this->assertTrue($page['editor']['outside']);
        $this->assertCount(2, $this->entries());
    }

    public function testPanelSaveAfterAnOutsideChangeKeepsBoth(): void
    {
        $this->app();
        $this->panelEntry();
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

        // page.update:before, the save, page.update:after
        $page = kirby()->page('test-page');
        (new ExternalChanges())->check($page);
        $updated = $page->update(['title' => 'Saved in the Panel']);
        (new ChangeTracker())->trackContentChange($updated);

        $entries = $this->entries();
        $this->assertCount(3, $entries);
        $this->assertSame('kirby', $entries[0]['editor_id']);
        $this->assertNull($entries[1]['editor_id']);

        // the diff of the Panel save shows only the title, not the deployed text
        $diff = (new ContentDiffResolver())->compare($this->pageDir, 'article', $entries[1]['uuid'], $entries[0]['uuid']);
        $this->assertSame(1, $diff['changed']);
        $this->assertStringContainsString('Saved in the Panel', $diff['html']);
    }

    public function testDiffAndRestoreWorkWithAnOutsideEntry(): void
    {
        $this->app();
        $this->panelEntry();
        $panel = $this->entries()[0];
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");
        (new ExternalChanges())->check(kirby()->page('test-page'));
        $outside = $this->entries()[0];

        $diff = (new ContentDiffResolver())->compare($this->pageDir, 'article', $panel['uuid'], $outside['uuid']);
        $this->assertSame(1, $diff['changed']);
        $this->assertStringContainsString('Deployed', $diff['html']);

        // back to the Panel version, recorded as a Panel entry
        (new ContentRestore())->restore($this->pageDir, 'article', $panel['uuid']);
        $this->assertStringContainsString('Text: Hello', file_get_contents($this->contentFile));
        $this->assertSame(['kirby', null, 'kirby'], array_column($this->entries(), 'editor_id'));
    }

    public function testAnOutsideEntryCanBeRestored(): void
    {
        $this->writeHistory($this->pageDir, ['article' => [
            [
                'editor_id' => null,
                'source'    => 'external',
                'uuid'      => 'outside',
                'time'      => time() - 60,
                'version'   => 2,
                'type'      => 'page',
                'language'  => '',
                'content'   => "Title: Test Page\n----\nText: Deployed\n",
            ],
        ]]);
        $this->app();

        (new ContentRestore())->restore($this->pageDir, 'article', 'outside');

        $this->assertStringContainsString('Text: Deployed', file_get_contents($this->contentFile));
        // the current content differed from the outside entry: it is kept first
        $this->assertSame(['kirby', null, null], array_column($this->entries(), 'editor_id'));
    }

    public function testRestoreKeepsAnUnnoticedOutsideChange(): void
    {
        $this->app();
        $this->panelEntry();
        $panel = $this->entries()[0];

        // the next Panel action is a restore, before anyone opened Content Watch
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");
        (new ContentRestore())->restore($this->pageDir, 'article', $panel['uuid']);

        $entries = $this->entries();
        $this->assertCount(3, $entries);
        $this->assertSame('restored', $entries[0]['action']);
        $this->assertNull($entries[1]['editor_id']);
        $this->assertStringContainsString('Deployed', $entries[1]['content']);
    }

    // -------------------------------------------------------------------------
    // Review findings
    // -------------------------------------------------------------------------

    /**
     * The plugin's own hooks: a Panel save keeps working with the option
     * off and on, and records the outside version before the save
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginHooksKeepSavingWorking(): void
    {
        require dirname(__DIR__, 2) . '/index.php';

        foreach ([false, true] as $enabled) {
            $this->writeContent($this->contentFile, "Title: Test Page\n----\nText: Hello\n");
            @unlink($this->pageDir . '/.content-watch.json');
            $this->app(['tearoom1.kirby-content-watch.detectExternal' => $enabled]);

            $page = kirby()->page('test-page')->update(['text' => 'First save']);
            ChangeTracker::resetRequestTrackedEntries();
            $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

            $page = kirby()->page('test-page')->update(['title' => 'Second save']);

            $this->assertSame('Second save', $page->title()->value());
            $this->assertSame(
                $enabled ? ['kirby', null, 'kirby'] : ['kirby', 'kirby'],
                array_column($this->entries(), 'editor_id')
            );
        }
    }

    public function testPanelStructureChangeInOneLanguageIsNoOutsideChange(): void
    {
        $this->writeContent($this->pageDir . '/article.en.txt', "Title: English\n");
        $this->writeContent($this->pageDir . '/article.de.txt', "Title: Deutsch\n");
        unlink($this->contentFile);
        $this->app([], ['languages' => [
            ['code' => 'en', 'name' => 'English', 'default' => true],
            ['code' => 'de', 'name' => 'Deutsch'],
        ]]);

        (new ChangeTracker())->trackContentChange(kirby()->page('test-page'), ['languages' => ['en', 'de']]);

        // a status change in the Panel, recorded in the current language only
        $page = kirby()->page('test-page')->changeStatus('unlisted');
        ChangeTracker::resetRequestTrackedEntries();
        (new ChangeTracker())->trackContentChange($page);

        $this->pageDir = $page->root();
        $this->assertCount(3, $this->entries());

        $this->assertFalse((new ExternalChanges())->check($page));
        $this->assertCount(3, $this->entries());
    }

    public function testStructureChangedOutsideIsRecordedOnce(): void
    {
        $this->app();
        $this->panelEntry();

        // a deploy renames the folder: the page is unlisted now
        rename($this->pageDir, $this->contentDir . '/test-page');
        $this->pageDir = $this->contentDir . '/test-page';
        $this->app();

        $page = kirby()->page('test-page');
        $this->assertTrue((new ExternalChanges())->check($page));
        $this->assertFalse((new ExternalChanges())->check($page));
        $this->assertSame('unlisted', $this->entries()[0]['meta']['status']);
    }

    public function testComparesTheContentAsItIsWhenTheLockIsTaken(): void
    {
        $this->app();
        $this->panelEntry();
        $this->editOutside("Title: Test Page\n----\nText: Deployed\n");

        $page = kirby()->page('test-page');

        // another request records the outside change and a Panel save in between
        $tracker = new class extends ChangeTracker {
            public $interleave;

            protected function storeRecord(string $dirPath, string $fileKey, array &$record, array $meta, ?callable $guard = null): ?bool
            {
                if ($this->interleave !== null) {
                    $interleave = $this->interleave;
                    $this->interleave = null;
                    $interleave();
                }

                return parent::storeRecord($dirPath, $fileKey, $record, $meta, $guard);
            }
        };
        $tracker->interleave = function () use ($page) {
            (new ExternalChanges())->check($page);
            file_put_contents($this->contentFile, "Title: Test Page\n----\nText: Saved in the Panel\n");
            clearstatcache();
            ChangeTracker::resetRequestTrackedEntries();
            (new ChangeTracker())->trackContentChange($page);
        };

        $this->assertFalse($tracker->recordExternalChange($page, ''));
        $this->assertSame(['kirby', null, 'kirby'], array_column($this->entries(), 'editor_id'));
        $this->assertFalse((new ExternalChanges())->check($page));
    }

    public function testFollowsATemplateRenamedOutside(): void
    {
        $this->app();
        $this->panelEntry();

        rename($this->contentFile, $this->pageDir . '/note.txt');
        $this->app();

        $page = kirby()->page('test-page');
        $this->assertSame('note', $page->intendedTemplate()->name());
        $this->assertTrue((new ExternalChanges())->check($page));
        $this->assertFalse((new ExternalChanges())->check($page));

        $history = $this->readHistory($this->pageDir);
        $this->assertArrayNotHasKey('article', $history);
        $this->assertCount(2, $history['note']);
        $this->assertNull($history['note'][0]['editor_id']);
        $this->assertSame('note', $history['note'][0]['meta']['template']);
    }

    public function testAreaFollowsATemplateRenamedOutside(): void
    {
        $this->app(['tearoom1.kirby-content-watch.enableRestore' => false]);
        $this->panelEntry();

        rename($this->contentFile, $this->pageDir . '/note.txt');
        $this->app(['tearoom1.kirby-content-watch.enableRestore' => false]);

        $files = (new ContentWatchController())->getContentFiles();
        $page  = array_values(array_filter($files, fn ($file) => $file['id'] === 'test-page'))[0];

        $this->assertCount(2, $page['history']);
        $this->assertTrue($page['editor']['outside']);
    }
}
