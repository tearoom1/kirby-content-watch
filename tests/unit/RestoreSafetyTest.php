<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\Tests\TestCase;

/**
 * Regression tests for the restore findings of review CW-REVIEW-1 (2.1, 2.2, 2.4)
 * and the permission checks of the Kirby based restore (2.3)
 */
class RestoreSafetyTest extends TestCase
{
    protected function app(array $overrides = []): void
    {
        $this->kirby = $this->makeApp(array_replace_recursive([
            'options' => ['tearoom1.kirby-content-watch.enableRestore' => true],
        ], $overrides));
        $this->kirby->impersonate('kirby');
    }

    protected function entry(string $id, string $content, array $meta = [], string $language = '', int $version = 1): array
    {
        return array_filter([
            'editor_id' => 'kirby',
            'uuid'      => $id,
            'time'      => time() - 1000 + $version,
            'version'   => $version,
            'type'      => 'page',
            'language'  => $language,
            'content'   => $content,
            'meta'      => $meta ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * 2.1: page `a` was renamed to `b`, a new page `a` exists. Restoring the
     * old version of `b` (slug `a`) must not write into the other page.
     */
    public function testRestoreNeverWritesIntoAnotherPagesFolder(): void
    {
        $this->writeContent($this->contentDir . '/a/default.txt', "Title: OTHER PAGE\n");
        $this->writeContent($this->contentDir . '/b/default.txt', "Title: B current\n");
        $this->writeHistory($this->contentDir . '/b', ['default' => [
            $this->entry('old', "Title: A original\n", ['path' => '', 'slug' => 'a', 'status' => 'unlisted', 'template' => 'default']),
        ]]);
        $this->app();

        $restore = new ContentRestore();
        $this->assertFalse($restore->restoreContent($this->contentDir . '/b', 'default', 'old'));
        $this->assertStringContainsString('already uses', (string)$restore->error());

        $this->assertSame("Title: OTHER PAGE\n", file_get_contents($this->contentDir . '/a/default.txt'));
        $this->assertSame("Title: B current\n", file_get_contents($this->contentDir . '/b/default.txt'));
    }

    /**
     * 2.2: restoring a translation must not rename the folder to the translated slug
     */
    public function testRestoringATranslationKeepsTheFolder(): void
    {
        $this->writeContent($this->contentDir . '/haus/default.en.txt', "Title: House\n");
        $this->writeContent($this->contentDir . '/haus/default.de.txt', "Title: Haus neu\n----\nSlug: haus-de\n");
        // entry written by older versions: the translated slug ended up in the meta
        $this->writeHistory($this->contentDir . '/haus', ['default' => [
            $this->entry('de-old', "Title: Haus alt\n----\nSlug: haus-de\n", ['path' => '', 'slug' => 'haus-de', 'status' => 'unlisted', 'template' => 'default'], 'de'),
        ]]);
        $this->app(['languages' => [
            ['code' => 'en', 'name' => 'English', 'default' => true],
            ['code' => 'de', 'name' => 'Deutsch'],
        ]]);

        $restore = new ContentRestore();
        $this->assertTrue($restore->restoreContent($this->contentDir . '/haus', 'default', 'de-old'), (string)$restore->error());

        $this->assertDirectoryExists($this->contentDir . '/haus');
        $this->assertDirectoryDoesNotExist($this->contentDir . '/haus-de');
        $this->assertStringContainsString('Haus alt', file_get_contents($this->contentDir . '/haus/default.de.txt'));
        $this->assertStringContainsString('House', file_get_contents($this->contentDir . '/haus/default.en.txt'));
    }

    /**
     * 2.2: the tracker stores the slug of the default language as folder name
     */
    public function testTrackerStoresTheDefaultLanguageSlug(): void
    {
        $this->writeContent($this->contentDir . '/haus/default.en.txt', "Title: House\n");
        $this->writeContent($this->contentDir . '/haus/default.de.txt', "Title: Haus\n----\nSlug: haus-de\n");
        $this->app(['languages' => [
            ['code' => 'en', 'name' => 'English', 'default' => true],
            ['code' => 'de', 'name' => 'Deutsch'],
        ]]);
        $this->kirby->setCurrentLanguage('de');

        (new ChangeTracker())->trackContentChange($this->kirby->page('haus'));

        $entry = $this->readHistory($this->contentDir . '/haus')['default'][0];
        $this->assertSame('de', $entry['language']);
        $this->assertSame('haus', $entry['meta']['slug']);
    }

    /**
     * 2.4: changing the template back must not rename the metadata of files
     * whose name starts with the template name
     */
    public function testTemplateRestoreLeavesFileMetadataAlone(): void
    {
        $dir = $this->contentDir . '/1_party';
        $this->writeContent($dir . '/note.txt', "Title: Party\n");
        $this->writeContent($dir . '/event-poster.jpg', 'jpg');
        $this->writeContent($dir . '/event-poster.jpg.txt', "Alt: The poster\n");
        $this->writeHistory($dir, ['note' => [
            $this->entry('as-event', "Title: Party\n", ['path' => '', 'slug' => 'party', 'status' => 'listed', 'template' => 'event']),
        ]]);
        $this->app(['blueprints' => [
            'pages/event' => ['options' => ['changeTemplate' => ['event', 'note']]],
            'pages/note'  => ['options' => ['changeTemplate' => ['event', 'note']]],
        ]]);

        $restore = new ContentRestore();
        $this->assertTrue($restore->restoreContent($dir, 'note', 'as-event'), (string)$restore->error());

        $this->assertFileExists($dir . '/event.txt');
        $this->assertFileExists($dir . '/event-poster.jpg.txt');
        $this->assertSame("Alt: The poster\n", file_get_contents($dir . '/event-poster.jpg.txt'));
    }

    protected function editorApp(array $permissions): void
    {
        $this->app([
            'options' => ['tearoom1.kirby-content-watch.allowedRoles' => ['editor']],
            'roles' => [['name' => 'editor', 'permissions' => ['pages' => $permissions]]],
            'users' => [['id' => 'ed', 'email' => 'editor@example.com', 'role' => 'editor']],
        ]);
        $this->kirby->impersonate('editor@example.com');
    }

    public function testEditorWithoutChangeStatusPermissionIsRefusedAndNothingChanges(): void
    {
        $dir = $this->contentDir . '/1_page';
        $this->writeContent($dir . '/default.txt', "Title: Published\n");
        $this->writeHistory($dir, ['default' => [
            $this->entry('draft', "Title: Draft version\n", ['path' => '', 'slug' => 'page', 'status' => 'draft', 'template' => 'default']),
        ]]);
        $this->editorApp(['changeStatus' => false]);

        $restore = new ContentRestore();
        $this->assertFalse($restore->restoreContent($dir, 'default', 'draft'));
        $this->assertStringContainsString('not allowed to change the status', (string)$restore->error());

        $this->assertSame("Title: Published\n", file_get_contents($dir . '/default.txt'));
        $this->assertDirectoryDoesNotExist($this->contentDir . '/_drafts');
        $this->assertCount(1, $this->readHistory($dir)['default']);
    }

    public function testEditorWithoutChangeSlugPermissionIsRefusedAndNothingChanges(): void
    {
        $dir = $this->contentDir . '/1_new-name';
        $this->writeContent($dir . '/default.txt', "Title: Now\n");
        $this->writeHistory($dir, ['default' => [
            $this->entry('old-name', "Title: Before\n", ['path' => '', 'slug' => 'old-name', 'status' => 'listed', 'template' => 'default']),
        ]]);
        $this->editorApp(['changeSlug' => false]);

        $restore = new ContentRestore();
        $this->assertFalse($restore->restoreContent($dir, 'default', 'old-name'));
        $this->assertStringContainsString('URL appendix', (string)$restore->error());

        $this->assertSame("Title: Now\n", file_get_contents($dir . '/default.txt'));
        $this->assertDirectoryDoesNotExist($this->contentDir . '/1_old-name');
    }

    public function testEditorWithoutUpdatePermissionIsRefused(): void
    {
        $dir = $this->contentDir . '/1_page';
        $this->writeContent($dir . '/default.txt', "Title: Now\n");
        $this->writeHistory($dir, ['default' => [
            $this->entry('before', "Title: Before\n", ['path' => '', 'slug' => 'page', 'status' => 'listed', 'template' => 'default']),
        ]]);
        $this->editorApp(['update' => false]);

        $this->assertFalse(ContentRestore::canRestore($this->kirby->page('page')));

        $restore = new ContentRestore();
        $this->assertFalse($restore->restoreContent($dir, 'default', 'before'));
        $this->assertSame("Title: Now\n", file_get_contents($dir . '/default.txt'));
    }

    public function testEditorWithPermissionsRestoresThroughKirby(): void
    {
        $dir = $this->contentDir . '/1_page';
        $this->writeContent($dir . '/default.txt', "Title: Now\n----\nText: Now text\n");
        $this->writeHistory($dir, ['default' => [
            $this->entry('before', "Title: Before\n", ['path' => '', 'slug' => 'page', 'status' => 'unlisted', 'template' => 'default']),
        ]]);
        $this->editorApp([]);

        $this->assertTrue(ContentRestore::canRestore($this->kirby->page('page')));

        $restore = new ContentRestore();
        $this->assertTrue($restore->restoreContent($dir, 'default', 'before'), (string)$restore->error());

        // the status changed through Kirby: the folder lost its sorting number
        $file = $this->contentDir . '/page/default.txt';
        $this->assertFileExists($file);
        $this->assertStringContainsString('Title: Before', file_get_contents($file));
        $this->assertStringNotContainsString('Now text', file_get_contents($file));

        $entry = $this->readHistory($this->contentDir . '/page')['default'][0];
        $this->assertSame('ed', $entry['editor_id']);
        $this->assertSame('restored', $entry['action']);
    }

    public function testUnsavedChangesBlockTheRestore(): void
    {
        $dir = $this->contentDir . '/1_page';
        $this->writeContent($dir . '/default.txt', "Title: Now\n");
        $this->writeContent($dir . '/_changes/default.txt', "Title: Unsaved\n");
        $this->writeHistory($dir, ['default' => [
            $this->entry('before', "Title: Before\n", ['path' => '', 'slug' => 'page', 'status' => 'listed', 'template' => 'default']),
        ]]);
        $this->app();

        if (!method_exists($this->kirby->page('page'), 'version')) {
            $this->markTestSkipped('Kirby 4 has no unsaved changes versions');
        }

        $restore = new ContentRestore();
        $this->assertFalse($restore->restoreContent($dir, 'default', 'before'));
        $this->assertStringContainsString('unsaved changes', (string)$restore->error());
        $this->assertSame("Title: Now\n", file_get_contents($dir . '/default.txt'));
    }
}
