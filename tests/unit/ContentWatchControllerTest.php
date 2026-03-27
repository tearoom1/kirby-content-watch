<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ContentWatchController;
use TearoomOne\ContentWatch\Tests\TestCase;

class ContentWatchControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // isDefaultContentFile()
    // -------------------------------------------------------------------------

    public function testIsDefaultContentFileReturnsTrueForTxtInSingleLang(): void
    {
        $controller = new ContentWatchController();
        $file = new \SplFileInfo($this->contentDir . '/1_home/home.txt');

        $this->assertTrue($controller->isDefaultContentFile($file));
    }

    public function testIsDefaultContentFileReturnsFalseForNonTxt(): void
    {
        $controller = new ContentWatchController();
        $file = new \SplFileInfo($this->contentDir . '/1_home/image.jpg');

        $this->assertFalse($controller->isDefaultContentFile($file));
    }

    public function testIsDefaultContentFileMatchesDefaultLanguageInMultilang(): void
    {
        $this->kirby = $this->makeApp([
            'languages' => [
                ['code' => 'en', 'name' => 'English', 'default' => true],
                ['code' => 'de', 'name' => 'German'],
            ],
        ]);

        $controller = new ContentWatchController();

        $enFile = new \SplFileInfo($this->contentDir . '/1_home/home.en.txt');
        $deFile = new \SplFileInfo($this->contentDir . '/1_home/home.de.txt');

        $this->assertTrue($controller->isDefaultContentFile($enFile));
        $this->assertFalse($controller->isDefaultContentFile($deFile));
    }

    // -------------------------------------------------------------------------
    // getEditor()
    // -------------------------------------------------------------------------

    public function testGetEditorReturnsUnknownForNullRecord(): void
    {
        $editor = (new ContentWatchController())->getEditor(null);

        $this->assertSame('unknown', $editor['id']);
        $this->assertSame('Unknown', $editor['name']);
    }

    public function testGetEditorReturnsUnknownForMissingEditorId(): void
    {
        $editor = (new ContentWatchController())->getEditor(['time' => time(), 'version' => 1]);

        $this->assertSame('unknown', $editor['id']);
    }

    public function testGetEditorReturnsUnknownForNonExistentUserId(): void
    {
        $editor = (new ContentWatchController())->getEditor(['editor_id' => 'no-such-user-xyz']);

        $this->assertSame('unknown', $editor['id']);
    }

    public function testGetEditorReturnsUnknownForKirbySystemUser(): void
    {
        // 'kirby' is a virtual impersonation user; it is NOT in the accounts directory,
        // so kirby()->user('kirby') returns null → getEditor falls back to 'unknown'.
        $editor = (new ContentWatchController())->getEditor(['editor_id' => 'kirby']);

        $this->assertSame('unknown', $editor['id']);
    }

    // -------------------------------------------------------------------------
    // getContentFiles()
    // -------------------------------------------------------------------------

    public function testGetContentFilesReturnsEmptyArrayWhenNoContentFiles(): void
    {
        $files = (new ContentWatchController())->getContentFiles();

        $this->assertIsArray($files);
        $this->assertEmpty($files);
    }

    public function testGetContentFilesDetectsPageContentFile(): void
    {
        $this->writeContent($this->contentDir . '/1_home/home.txt', "Title: Home\n");
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertCount(1, $files);
        $this->assertSame('home', $files[0]['id']);
        $this->assertSame('listed', $files[0]['page_status']);
    }

    public function testGetContentFilesMarksDraftPagesAsDraft(): void
    {
        $this->writeContent($this->contentDir . '/_drafts/article/article.txt', "Title: Draft Article\n");
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertCount(1, $files);
        $this->assertSame('draft', $files[0]['page_status']);
    }

    public function testGetContentFilesMarksUnlistedPagesAsUnlisted(): void
    {
        $this->writeContent($this->contentDir . '/article/article.txt', "Title: Article\n");
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertCount(1, $files);
        $this->assertSame('unlisted', $files[0]['page_status']);
    }

    public function testGetContentFilesExposesPageTemplate(): void
    {
        $this->writeContent($this->contentDir . '/article/article.txt', "Title: Article\n");
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertCount(1, $files);
        $this->assertSame('article', $files[0]['page_template']);
    }

    public function testGetContentFilesDetectsSiteFile(): void
    {
        $this->writeContent($this->contentDir . '/site.txt', "Title: My Site\n");
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $ids = array_column($files, 'id');
        $this->assertContains('site', $ids);
    }

    public function testGetContentFilesIsSortedNewestFirst(): void
    {
        $this->writeContent($this->contentDir . '/1_older/article.txt', "Title: Older\n");
        // Ensure different mtime by writing history for the second one
        sleep(1);
        $this->writeContent($this->contentDir . '/2_newer/article.txt', "Title: Newer\n");

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertCount(2, $files);
        $this->assertGreaterThanOrEqual($files[1]['modified'], $files[0]['modified']);
    }

    public function testGetContentFilesDetectsMediaFile(): void
    {
        $dir = $this->contentDir . '/1_home';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/photo.jpg', ''); // companion asset file (no extension in sense of no .txt)
        $this->writeContent($dir . '/photo.jpg.txt', "Alt: A photo\n");
        $this->writeContent($dir . '/home.txt', "Title: Home\n");

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files      = (new ContentWatchController())->getContentFiles();
        $mediaFiles = array_filter($files, fn($f) => $f['is_media_file']);

        $this->assertCount(1, $mediaFiles);
    }

    public function testGetContentFilesExcludesChangesDirectory(): void
    {
        $this->writeContent($this->contentDir . '/1_home/home.txt', "Title: Home\n");
        $this->writeContent($this->contentDir . '/1_home/_changes/home.txt', "Title: Draft\n");

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertCount(1, $files);
    }

    public function testGetContentFilesIncludesHistoryFromJson(): void
    {
        $pageDir = $this->contentDir . '/1_home';
        $this->writeContent($pageDir . '/home.txt', "Title: Home\n");
        $this->writeHistory($pageDir, [
            // Key must match intendedTemplate()->name() for this content file
            'home' => [[
                'editor_id' => 'kirby',
                'time'      => time(),
                'version'   => 1,
                'type'      => 'page',
            ]],
        ]);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertCount(1, $files[0]['history']);
    }

    public function testGetContentFilesExposesMoveActionInHistory(): void
    {
        $pageDir = $this->contentDir . '/1_home';
        $this->writeContent($pageDir . '/home.txt', "Title: Home\n");
        $this->writeHistory($pageDir, [
            'home' => [[
                'editor_id' => 'kirby',
                'time'      => time(),
                'version'   => 2,
                'type'      => 'page',
                'action'    => 'moved',
            ]],
        ]);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $files = (new ContentWatchController())->getContentFiles();

        $this->assertSame('moved', $files[0]['history'][0]['action']);
        $this->assertArrayNotHasKey('move', $files[0]['history'][0]);
    }
}
