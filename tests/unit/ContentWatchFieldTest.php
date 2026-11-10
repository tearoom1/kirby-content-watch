<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ContentWatchField;
use TearoomOne\ContentWatch\Tests\TestCase;

class ContentWatchFieldTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $time = time();
        $this->writeContent($this->contentDir . '/1_tea/article.txt', "Title: Tea\n");
        $this->writeHistory($this->contentDir . '/1_tea', ['article' => [
            ['uuid' => 'c', 'editor_id' => 'kirby', 'time' => $time - 10, 'version' => 3, 'content' => "Title: Tea\n"],
            ['uuid' => 'b', 'editor_id' => 'kirby', 'time' => $time - 20, 'version' => 2, 'content' => "Title: Tea 2\n"],
            ['uuid' => 'a', 'editor_id' => 'kirby', 'time' => $time - 30, 'version' => 1, 'content' => "Title: Tea 1\n"],
        ]]);
    }

    protected function field(array $props = []): ContentWatchField
    {
        return new ContentWatchField(...[
            'model' => $this->kirby->page('tea'),
            'name'  => 'history',
            ...$props
        ]);
    }

    public function testLabelDefaultsToRecentChanges(): void
    {
        $this->assertSame('Recent changes', $this->field()->label());

        // blueprints fill in the labelized name, that is no label of its own
        $this->assertSame('Recent changes', $this->field(['label' => 'History'])->label());
    }

    public function testHeadlineOfTheFormerSectionStillWorks(): void
    {
        $this->assertSame('Page history', $this->field(['headline' => 'Page history', 'label' => 'History'])->label());
        $this->assertSame('Changes', $this->field(['headline' => 'Page history', 'label' => 'Changes'])->label());
    }

    public function testEntriesAreLimitedAndKnowTheirPreviousVersion(): void
    {
        $entries = $this->field(['limit' => 2])->entries();

        $this->assertCount(2, $entries);
        $this->assertSame(3, $entries[0]['version']);
        $this->assertSame(2, $entries[0]['previous']['version']);
        $this->assertSame(1, $entries[1]['previous']['version']);
    }

    public function testPropsCarryTheStateForTheVueComponent(): void
    {
        $props = $this->field()->toArray();

        $this->assertSame('contentwatch', $props['type']);
        $this->assertSame(5, $props['limit']);
        $this->assertFalse($props['saveable']);
        $this->assertTrue($props['canAccess']);
        $this->assertSame('Recent changes', $props['headline']);
        $this->assertSame('content-watch?search=Tea', $props['areaUrl']);
        $this->assertCount(3, $props['entries']);
        $this->assertSame('article', $props['history']['fileKey']);
        $this->assertFalse($props['history']['enableRestore']);
    }

    public function testEndpointReturnsTheSameState(): void
    {
        $field = $this->field();
        $route = $field->api()[0];

        $this->assertSame('', $route['pattern']);
        $this->assertSame($field->state(), $route['action']());
    }

    public function testNothingForUsersWithoutAccess(): void
    {
        $this->kirby->impersonate(null);

        $props = $this->field()->toArray();

        $this->assertFalse($props['canAccess']);
        $this->assertSame([], $props['entries']);
        $this->assertArrayNotHasKey('history', $props);
    }
}
