<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use Kirby\Cms\Section;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use TearoomOne\ContentWatch\Tests\TestCase;

class ContentWatchSectionTest extends TestCase
{
    /**
     * The history section pairs each entry with the previous version
     * in the same language, as the Content Watch area does
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThePreviousVersionIsInTheSameLanguage(): void
    {
        require dirname(__DIR__, 2) . '/index.php';

        // a new app picks up the section the plugin registered
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $time = time();
        $this->writeContent($this->contentDir . '/1_tea/article.txt', "Title: Tea\n");
        $this->writeHistory($this->contentDir . '/1_tea', ['article' => [
            ['uuid' => 'd', 'editor_id' => 'kirby', 'time' => $time - 10, 'version' => 4, 'language' => 'de', 'content' => "Title: Tee\n"],
            ['uuid' => 'c', 'editor_id' => 'kirby', 'time' => $time - 20, 'version' => 3, 'language' => 'en', 'content' => "Title: Tea\n"],
            ['uuid' => 'b', 'editor_id' => 'kirby', 'time' => $time - 30, 'version' => 2, 'language' => 'de', 'content' => "Title: Tee 2\n"],
            ['uuid' => 'a', 'editor_id' => 'kirby', 'time' => $time - 40, 'version' => 1, 'language' => 'en', 'content' => "Title: Tea 1\n"],
        ]]);

        $section = new Section('contentwatch', [
            'model' => $this->kirby->page('tea'),
            'name'  => 'history',
        ]);

        $entries = $section->entries();

        $this->assertSame(2, $entries[0]['previous']['version']);
        $this->assertSame(1, $entries[1]['previous']['version']);
        $this->assertNull($entries[2]['previous']);
        $this->assertNull($entries[3]['previous']);
    }
}
