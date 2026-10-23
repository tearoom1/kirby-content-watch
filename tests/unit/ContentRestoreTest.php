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

    private const TEMPLATE_BLUEPRINTS = [
        'blueprints' => [
            'pages/article' => [
                'title'   => 'Article',
                'options' => ['changeTemplate' => ['article', 'note']],
                'fields'  => ['text' => ['type' => 'textarea']],
            ],
            'pages/note' => [
                'title'   => 'Note',
                'options' => ['changeTemplate' => ['article', 'note']],
                'fields'  => ['text' => ['type' => 'textarea']],
            ],
        ],
    ];

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

    public function testReturnsFalseForDirectoryWithoutContentModel(): void
    {
        $this->seedHistory();
        // _drafts itself is a container, not a page
        $orphanDir = $this->contentDir . '/_drafts';
        $this->writeContent($orphanDir . '/test-draft/article.txt', "Title: Draft\n");
        $this->writeHistory($orphanDir, [
            'article' => [[
                'uuid'    => 'orphan-entry',
                'time'    => 1000000,
                'version' => 1,
                'content' => "Title: Injected\n",
            ]],
        ]);

        $result = (new ContentRestore())->restoreContent($orphanDir, 'article', 'orphan-entry');

        $this->assertFalse($result);
        $this->assertFileDoesNotExist($orphanDir . '/article.txt');
    }

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

    public function testRestoreAppendsAnEntryAndKeepsTheHistory(): void
    {
        $this->seedHistory();
        $this->writeHistory($this->pageDir, [
            'article' => [
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'restore-current',
                    'time'      => time() - 100,
                    'version'   => 2,
                    'type'      => 'page',
                    'language'  => '',
                    'content'   => "Title: Current\n",
                ],
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'restore-old',
                    'time'      => time() - 200,
                    'version'   => 1,
                    'type'      => 'page',
                    'language'  => '',
                    'content'   => "Title: Old\n",
                ],
            ],
        ]);

        $this->assertTrue((new ContentRestore())->restoreContent($this->pageDir, 'article', 'restore-old'));

        $history = $this->readHistory($this->pageDir)['article'];

        // 2.6: nothing is removed or rewritten, the restore is a new version on top
        $this->assertSame([3, 2, 1], array_column($history, 'version'));
        $this->assertSame('restored', $history[0]['action']);
        $this->assertSame(1, $history[0]['restored_from_version']);
        $this->assertSame('restore-old', $history[0]['restored_from_id']);
    }

    // -------------------------------------------------------------------------
    // Multilingual restore
    // -------------------------------------------------------------------------

    public function testRestoreWritesLanguageSuffixedFile(): void
    {
        $this->writeContent($this->pageDir . '/article.en.txt', "Title: English Current\n");
        $langFile = $this->pageDir . '/article.de.txt';
        $this->writeContent($langFile, "Title: Deutsch Aktuell\n");
        unlink($this->contentFile);

        $this->writeHistory($this->pageDir, [
            'article' => [[
                'editor_id' => 'kirby',
                'uuid'      => 'restore-lang',
                'time'      => 1000000,
                'version'   => 1,
                'type'      => 'page',
                'language'  => 'de',
                'content'   => "Title: Deutsch Alt\n",
            ]],
        ]);

        $this->seedHistory(['languages' => [
            ['code' => 'en', 'name' => 'English', 'default' => true],
            ['code' => 'de', 'name' => 'Deutsch'],
        ]]);

        $restore = new ContentRestore();
        $result  = $restore->restoreContent($this->pageDir, 'article', 'restore-lang', 1000000);

        $this->assertTrue($result, (string)$restore->error());
        $this->assertStringContainsString('Deutsch Alt', file_get_contents($langFile));
        $this->assertStringContainsString('English Current', file_get_contents($this->pageDir . '/article.en.txt'));
    }

    public function testRestoreRestoresSlugTemplateAndTitle(): void
    {
        $this->seedHistory(self::TEMPLATE_BLUEPRINTS);
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
        $this->seedHistory(self::TEMPLATE_BLUEPRINTS);
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
