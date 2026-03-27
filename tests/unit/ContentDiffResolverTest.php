<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ContentDiffResolver;
use TearoomOne\ContentWatch\Tests\TestCase;

class ContentDiffResolverTest extends TestCase
{
    public function testGenerateComparesTwoHistoryEntries(): void
    {
        $pageDir = $this->contentDir . '/1_test-page';
        $this->writeContent($pageDir . '/article.txt', "Title: Current Title\n----\nText: Current body\n");
        $this->writeHistory($pageDir, [
            'article' => [
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'entry-current',
                    'time'      => 2000000,
                    'version'   => 2,
                    'type'      => 'page',
                    'content'   => "Title: Current Title\n----\nText: Current body\n",
                    'meta'      => [
                        'path'     => '',
                        'slug'     => 'test-page',
                        'status'   => 'listed',
                        'template' => 'article',
                    ],
                ],
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'entry-previous',
                    'time'      => 1000000,
                    'version'   => 1,
                    'type'      => 'page',
                    'content'   => "Title: Previous Title\n----\nText: Previous body\n",
                    'meta'      => [
                        'path'     => '',
                        'slug'     => 'test-page',
                        'status'   => 'draft',
                        'template' => 'article',
                    ],
                ],
            ],
        ]);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $diff = (new ContentDiffResolver())->generate(
            $pageDir,
            'article',
            'entry-previous',
            'entry-current',
            1000000,
            2000000
        );

        $this->assertStringContainsString('Previous', $diff);
        $this->assertStringContainsString('Current', $diff);
    }

    public function testGenerateThrowsWhenHistoryEntryDoesNotExist(): void
    {
        $pageDir = $this->contentDir . '/1_test-page';
        $this->writeContent($pageDir . '/article.txt', "Title: Current Title\n");
        $this->writeHistory($pageDir, [
            'article' => [],
        ]);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('One or both versions have no content');

        (new ContentDiffResolver())->generate(
            $pageDir,
            'article',
            'missing-from',
            'missing-to',
            1234567,
            9999999
        );
    }

    public function testGenerateUsesLatestStoredMetaWhenContentIsUnchanged(): void
    {
        $pageDir = $this->contentDir . '/1_test-page';
        $content = "Title: Same Title\n----\nText: Same body\n";
        $this->writeContent($pageDir . '/article.txt', $content);
        $this->writeHistory($pageDir, [
            'article' => [
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'entry-latest',
                    'time'      => 2000000,
                    'version'   => 2,
                    'type'      => 'page',
                    'content'   => $content,
                    'meta'      => [
                        'path'     => '',
                        'slug'     => 'test-page',
                        'status'   => 'listed',
                        'template' => 'article',
                    ],
                ],
                [
                    'editor_id' => 'kirby',
                    'uuid'      => 'entry-older',
                    'time'      => 1000000,
                    'version'   => 1,
                    'type'      => 'page',
                    'content'   => $content,
                    'meta'      => [
                        'path'     => '',
                        'slug'     => 'test-page',
                        'status'   => 'draft',
                        'template' => 'article',
                    ],
                ],
            ],
        ]);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $diff = (new ContentDiffResolver())->generate(
            $pageDir,
            'article',
            'entry-older',
            'entry-latest',
            1000000,
            2000000
        );

        $this->assertNotSame('No changes found', $diff);
        $this->assertStringContainsStringIgnoringCase('status', $diff);
        $this->assertStringContainsString('draft', $diff);
        $this->assertStringContainsString('listed', $diff);
    }

    public function testGenerateFallsBackToTimestampForLegacyEntries(): void
    {
        $pageDir = $this->contentDir . '/1_test-page';
        $this->writeHistory($pageDir, [
            'article' => [
                [
                    'editor_id' => 'kirby',
                    'time'      => 2000000,
                    'version'   => 2,
                    'type'      => 'page',
                    'content'   => "Title: Current\n",
                ],
                [
                    'editor_id' => 'kirby',
                    'time'      => 1000000,
                    'version'   => 1,
                    'type'      => 'page',
                    'content'   => "Title: Previous\n",
                ],
            ],
        ]);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $diff = (new ContentDiffResolver())->generate(
            $pageDir,
            'article',
            null,
            null,
            1000000,
            2000000
        );

        $this->assertStringContainsString('Previous', $diff);
        $this->assertStringContainsString('Current', $diff);
    }
}
