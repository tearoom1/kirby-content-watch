<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\Tests\TestCase;

class ContentRestoreTest extends TestCase
{
    private string $pageDir;
    private string $contentFile;
    // The template key is the intended template name (from content filename)
    private string $templateKey = 'article';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pageDir     = $this->contentDir . '/1_test-page';
        $this->contentFile = $this->pageDir . '/article.txt';

        $this->writeContent($this->contentFile, "Title: Current Title\n----\nText: Current text\n");
    }

    private function seedHistory(array $extraOptions = []): void
    {
        $this->kirby = $this->makeApp(array_replace_recursive([
            'options' => ['tearoom1.kirby-content-watch.enableRestore' => true],
        ], $extraOptions));
        $this->kirby->impersonate('kirby');
    }

    // -------------------------------------------------------------------------
    // Feature flag guard
    // -------------------------------------------------------------------------

    public function testReturnsFalseWhenRestoreDisabled(): void
    {
        // Default setup: enableRestore = false
        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-entry',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                'content'   => "Title: Old Title\n",
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 'restore-entry', 1000000);

        $this->assertFalse($result);
        // File should be unchanged
        $this->assertStringContainsString('Current Title', file_get_contents($this->contentFile));
    }

    // -------------------------------------------------------------------------
    // Missing data
    // -------------------------------------------------------------------------

    public function testReturnsFalseWhenHistoryFileDoesNotExist(): void
    {
        $this->seedHistory();

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 9999999);

        $this->assertFalse($result);
    }

    public function testReturnsFalseForUnknownTimestamp(): void
    {
        $this->seedHistory();
        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-entry',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                'content'   => "Title: Old Title\n",
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 9999999);

        $this->assertFalse($result);
    }

    public function testReturnsFalseWhenEntryHasNoContent(): void
    {
        $this->seedHistory();
        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                // 'content' intentionally missing
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 1000000);

        $this->assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // Successful restore
    // -------------------------------------------------------------------------

    public function testSuccessfulRestoreWritesOldContentToFile(): void
    {
        $this->seedHistory();
        $oldContent = "Title: Old Title\n----\nText: Old body\n";
        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-entry',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                'content'   => $oldContent,
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 'restore-entry', 1000000);

        $this->assertTrue($result);
        $this->assertStringContainsString('Old Title', file_get_contents($this->contentFile));
    }

    public function testSuccessfulRestoreAddsHistoryEntry(): void
    {
        $this->seedHistory();
        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-entry',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                'content'   => "Title: Old Title\n",
            ]],
        ]);

        $restore = new ContentRestore();
        $restore->restoreContent($this->pageDir, 'article', 'restore-entry', 1000000);

        $history    = $this->readHistory($this->pageDir);
        $newEntry   = $history['article'][0];

        $this->assertSame(1000000, $newEntry['restored_from']);
        $this->assertSame('restore-entry', $newEntry['restored_from_id']);
        $this->assertNotEmpty($newEntry['editor_id']);
        $this->assertArrayHasKey('uuid', $newEntry);
        $this->assertGreaterThanOrEqual(time() - 5, $newEntry['time']);
    }

    public function testRestoreRemovesOriginalVersionFromHistory(): void
    {
        $this->seedHistory();
        $this->writeHistory($this->pageDir, [
            'article' => [
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'restore-current',
                    'time'      => 2000000,
                    'version'   => 2,
                    'type'      => 'page',
                    'language'  => '',
                    'content'   => "Title: Current\n",
                ],
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'restore-old',
                    'time'      => 1000000,
                    'version'   => 1,
                    'type'      => 'page',
                    'language'  => '',
                    'content'   => "Title: Old\n",
                ],
            ],
        ]);

        $restore = new ContentRestore();
        $restore->restoreContent($this->pageDir, 'article', 'restore-old', 1000000);

        $history  = $this->readHistory($this->pageDir);
        $versions = array_column($history['article'], 'version');

        // Version 1 is removed; the restoration re-adds it tagged with restored_from
        $restoredEntries = array_filter($history['article'], fn($e) => isset($e['restored_from']));
        $this->assertCount(1, $restoredEntries);
    }

    // -------------------------------------------------------------------------
    // Multilingual restore
    // -------------------------------------------------------------------------

    public function testRestoreWritesLanguageSuffixedFile(): void
    {
        $this->seedHistory();
        $langFile = $this->pageDir . '/article.en.txt';
        $this->writeContent($langFile, "Title: English Current\n");

        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-lang',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => 'en',
                'content'   => "Title: English Old\n",
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 'restore-lang', 1000000);

        $this->assertTrue($result);
        $this->assertStringContainsString('English Old', file_get_contents($langFile));
    }

    public function testRestoreRestoresSlugTemplateAndTitle(): void
    {
        $this->seedHistory();
        $snapshot = "Title: Restored Title\n----\nText: Old body\n";

        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-structured',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                'content'   => $snapshot,
                'meta'      => [
                    'path'     => 'restored-page',
                    'slug'     => 'restored-page',
                    'status'   => 'listed',
                    'template' => 'note',
                ],
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 'restore-structured', 1000000);

        $this->assertTrue($result);
        $restoredFile = $this->contentDir . '/1_restored-page/note.txt';
        $this->assertFileExists($restoredFile);
        $this->assertFileDoesNotExist($this->pageDir . '/article.txt');
        $this->assertStringContainsString('Title: Restored Title', file_get_contents($restoredFile));
        $this->assertStringNotContainsString('Status:', file_get_contents($restoredFile));

        $history = $this->readHistory(dirname($restoredFile));
        $this->assertArrayHasKey('note', $history);
        $this->assertArrayNotHasKey('article', $history);
    }

    public function testRestoreRestoresDraftStatus(): void
    {
        $this->seedHistory();
        $snapshot = "Title: Draft Title\n----\nText: Draft body\n";

        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-draft',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                'content'   => $snapshot,
                'meta'      => [
                    'path'     => 'test-page',
                    'slug'     => 'test-page',
                    'status'   => 'draft',
                    'template' => 'article',
                ],
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 'restore-draft', 1000000);

        $this->assertTrue($result);
        $draftFile = $this->contentDir . '/_drafts/test-page/article.txt';
        $this->assertFileExists($draftFile);
        $this->assertFileDoesNotExist($this->pageDir . '/article.txt');
        $this->assertStringContainsString('Draft Title', file_get_contents($draftFile));
    }

    public function testRestoreSupportsLegacyInlineSnapshotMetadata(): void
    {
        $this->seedHistory();
        $legacySnapshot = implode('', [
            "Path: restored-page\n----\n",
            "Slug: restored-page\n----\n",
            "Status: listed\n----\n",
            "Template: note\n----\n",
            "Title: Restored Title\n----\n",
            "Text: Old body\n",
        ]);

        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => '',
                'content'   => $legacySnapshot,
            ]],
        ]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', null, 1000000);

        $this->assertTrue($result);
        $restoredFile = $this->contentDir . '/1_restored-page/note.txt';
        $this->assertFileExists($restoredFile);
        $this->assertStringContainsString('Title: Restored Title', file_get_contents($restoredFile));
        $this->assertStringNotContainsString('Path:', file_get_contents($restoredFile));
    }
}
