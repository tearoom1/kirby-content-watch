<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\SnapshotSerializer;
use TearoomOne\ContentWatch\Tests\TestCase;

class SnapshotSerializerTest extends TestCase
{
    public function testComposePrependsMetaFieldsForDiffs(): void
    {
        $result = SnapshotSerializer::compose(
            "Title: Hello\n----\nText: World\n",
            [
                'path'     => 'section/test-page',
                'slug'     => 'test-page',
                'status'   => 'listed',
                'template' => 'article',
            ]
        );

        $this->assertStringContainsString('Path: section/test-page', $result);
        $this->assertStringContainsString('Slug: test-page', $result);
        $this->assertStringContainsString('Status: listed', $result);
        $this->assertStringContainsString('Template: article', $result);
        $this->assertStringEndsWith("Title: Hello\n----\nText: World\n", $result);
    }

    public function testSplitSupportsLegacyInlineMetadata(): void
    {
        $result = SnapshotSerializer::split(implode('', [
            "Path: section/test-page\n----\n",
            "Slug: test-page\n----\n",
            "Status: draft\n----\n",
            "Template: article\n----\n",
            "Title: Hello\n----\nText: World\n",
        ]));

        $this->assertSame('section/test-page', $result['meta']['path'] ?? null);
        $this->assertSame('test-page', $result['meta']['slug'] ?? null);
        $this->assertSame('draft', $result['meta']['status'] ?? null);
        $this->assertSame('article', $result['meta']['template'] ?? null);
        $this->assertSame("Title: Hello\n----\nText: World\n", $result['content']);
    }
}
