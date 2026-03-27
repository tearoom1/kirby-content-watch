<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\DiffGenerator;
use TearoomOne\ContentWatch\Tests\TestCase;

class DiffGeneratorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // generate()
    // -------------------------------------------------------------------------

    public function testIdenticalContentReturnsNoChangesMessage(): void
    {
        $content = "Title: Hello\n----\nText: World";

        $this->assertSame('No changes found', DiffGenerator::generate($content, $content));
    }

    public function testIdenticalContentWithDifferentOuterWhitespaceReturnsNoChanges(): void
    {
        $content = "Title: Hello\n----\nText: World";

        $this->assertSame(
            'No changes found',
            DiffGenerator::generate("  $content  ", "  $content  ")
        );
    }

    public function testChangedFieldProducesDiffOutput(): void
    {
        $old = "Title: Hello\n----\nText: World";
        $new = "Title: Hello\n----\nText: Earth";

        $result = DiffGenerator::generate($old, $new);

        $this->assertNotEmpty($result);
        $this->assertNotSame('No changes found', $result);
    }

    public function testAddedFieldAppearsInDiff(): void
    {
        $old = "Title: Hello";
        $new = "Title: Hello\n----\nText: New text here";

        $result = DiffGenerator::generate($old, $new);

        $this->assertNotEmpty($result);
        $this->assertNotSame('No changes found', $result);
    }

    public function testChangedTitleWordAppearsInDiff(): void
    {
        $old = "Title: Old Title\n----\nText: Same";
        $new = "Title: New Title\n----\nText: Same";

        $result = DiffGenerator::generate($old, $new);

        $this->assertNotSame('No changes found', $result);
        // The simple diff wraps changed words in span tags — check for changed words
        $this->assertStringContainsString('Old', $result);
        $this->assertStringContainsString('New', $result);
    }

    public function testPathChangeAppearsInDiff(): void
    {
        $old = "Path: archive/test-page\n----\nSlug: test-page\n----\nTitle: Hello";
        $new = "Path: section/test-page\n----\nSlug: test-page\n----\nTitle: Hello";

        $result = DiffGenerator::generate($old, $new);

        $this->assertNotSame('No changes found', $result);
        $this->assertStringContainsString('archive/test-page', $result);
        $this->assertStringContainsString('section/test-page', $result);
    }

    // -------------------------------------------------------------------------
    // flattenJSON()
    // -------------------------------------------------------------------------

    public function testFlattenJSONPreservesSimpleStringFields(): void
    {
        $fields = [
            'title' => 'Hello World',
            'text'  => 'Some body text',
        ];

        $result = DiffGenerator::flattenJSON($fields);

        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('text', $result);
        $this->assertStringContainsString('Hello World', $result['title']);
        $this->assertStringContainsString('Some body text', $result['text']);
    }

    public function testFlattenJSONProcessesJsonArrayField(): void
    {
        $block  = [['id' => 'block1', 'type' => 'text', 'content' => 'Block content here']];
        $fields = ['blocks' => json_encode($block)];

        $result = DiffGenerator::flattenJSON($fields);

        // The original 'blocks' key is replaced by flattened keys
        $this->assertArrayNotHasKey('blocks', $result);
        $allValues = implode(' ', $result);
        $this->assertStringContainsString('Block content here', $allValues);
    }

    public function testFlattenJSONHandlesEmptyArray(): void
    {
        $this->assertSame([], DiffGenerator::flattenJSON([]));
    }

    // -------------------------------------------------------------------------
    // Unicode
    // -------------------------------------------------------------------------

    public function testUnicodeCharactersArePreservedInDiff(): void
    {
        $old = "Title: Résumé";
        $new = "Title: Curriculum Vitæ";

        $result = DiffGenerator::generate($old, $new);

        $this->assertNotSame('No changes found', $result);
    }
}
