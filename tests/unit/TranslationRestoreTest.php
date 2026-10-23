<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ChangeTracker;
use TearoomOne\ContentWatch\ContentRestore;
use TearoomOne\ContentWatch\Tests\TestCase;

class TranslationRestoreTest extends TestCase
{
    protected function app(): void
    {
        $this->kirby = $this->makeApp([
            'options'   => ['tearoom1.kirby-content-watch.enableRestore' => true],
            'languages' => [
                ['code' => 'en', 'name' => 'English', 'default' => true],
                ['code' => 'de', 'name' => 'Deutsch'],
            ],
            'blueprints' => [
                'pages/article' => [
                    'fields' => [
                        'text'            => ['type' => 'textarea'],
                        'metadescription' => ['type' => 'textarea'],
                        'cover'           => ['type' => 'files', 'translate' => false],
                        'ogimage'         => ['type' => 'files'],
                    ],
                ],
            ],
        ]);
        $this->kirby->impersonate('kirby');
    }

    public function testRestoringATranslationOnlyWritesItsOwnFields(): void
    {
        $dir = $this->contentDir . '/1_matcha';
        $this->writeContent($dir . '/article.en.txt', "Title: Matcha\n----\nText: English text\n----\nMetadescription: English description\n----\nCover: - file://abc\n----\nOgimage: - file://def\n");
        $this->writeContent($dir . '/article.de.txt', "Title: Matcha DE\n----\nText: Deutscher Text neu\n");
        $this->writeHistory($dir, ['article' => [[
            'editor_id' => 'kirby', 'uuid' => 'de-old', 'time' => time() - 100, 'version' => 1, 'language' => 'de',
            'content'   => "Title: Matcha DE\n----\nText: Deutscher Text alt\n",
        ]]]);
        $this->app();

        $restore = new ContentRestore();
        $this->assertTrue($restore->restoreContent($dir, 'article', 'de-old'), (string)$restore->error());

        $de = file_get_contents($dir . '/article.de.txt');
        $this->assertStringContainsString('Deutscher Text alt', $de);
        $this->assertStringNotContainsString('English description', $de);
        $this->assertStringNotContainsString('file://', $de);
        $this->assertStringNotContainsString('Metadescription:', $de);
        $this->assertStringNotContainsString('Cover:', $de);
        $this->assertStringNotContainsString('Ogimage:', $de);
        $this->assertStringContainsString('English description', file_get_contents($dir . '/article.en.txt'));
    }

    public function testProgrammaticUpdateOfATranslationIsRecordedInThatLanguage(): void
    {
        $dir = $this->contentDir . '/1_matcha';
        $this->writeContent($dir . '/article.en.txt', "Title: Matcha\n----\nText: English text\n");
        $this->writeContent($dir . '/article.de.txt', "Title: Matcha DE\n----\nText: Deutscher Text\n");
        $this->app();

        // plugin hooks are not loaded in unit tests: register the update hook like index.php does
        $this->kirby = $this->kirby->clone([
            'hooks' => [
                'page.update:after' => function ($newPage, $oldPage) {
                    (new ChangeTracker())->trackUpdate($newPage, $oldPage);
                },
            ],
        ]);
        $this->kirby->impersonate('kirby');
        $this->kirby->setCurrentLanguage('en');

        // e.g. a translation plugin writing German while the request is English
        $this->kirby->page('matcha')->update(['text' => 'Übersetzter Text'], 'de');

        $entries = $this->readHistory($dir)['article'];
        $this->assertCount(1, $entries);
        $this->assertSame('de', $entries[0]['language']);
        $this->assertStringContainsString('Übersetzter Text', $entries[0]['content']);
        $this->assertStringNotContainsString('English text', $entries[0]['content']);
    }

    public function testRestoreKeepsTheStoredFormatOfFields(): void
    {
        $dir    = $this->contentDir . '/1_matcha';
        $blocks = json_encode([['id' => 'a', 'type' => 'text', 'content' => ['text' => 'Alt'], 'isHidden' => false]], JSON_PRETTY_PRINT);
        $this->writeContent($dir . '/article.en.txt', "Title: Matcha\n----\nText: Neu\n----\nUuid: keep-me\n");
        $this->writeContent($dir . '/article.de.txt', "Title: Matcha DE\n");
        $this->writeHistory($dir, ['article' => [[
            'editor_id' => 'kirby', 'uuid' => 'en-old', 'time' => time() - 100, 'version' => 1, 'language' => 'en',
            'content'   => "Title: Matcha\n----\nText: " . $blocks . "\n----\nUuid: old-uuid\n",
        ]]]);
        $this->app();

        $restore = new ContentRestore();
        $this->assertTrue($restore->restoreContent($dir, 'article', 'en-old'), (string)$restore->error());

        $en = file_get_contents($dir . '/article.en.txt');
        $this->assertStringContainsString('"text": "Alt"', $en);
        $this->assertStringContainsString('Uuid: keep-me', $en);
        $this->assertSame("Title: Matcha DE\n", file_get_contents($dir . '/article.de.txt'));
    }
}
