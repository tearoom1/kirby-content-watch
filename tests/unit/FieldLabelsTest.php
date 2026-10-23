<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ContentDiffResolver;
use TearoomOne\ContentWatch\DiffGenerator;
use TearoomOne\ContentWatch\FieldLabels;
use TearoomOne\ContentWatch\Tests\TestCase;

class FieldLabelsTest extends TestCase
{
    public function testFromFieldsTranslatesLabelsIntoTheGivenLocale(): void
    {
        $labels = FieldLabels::fromFields([
            'ogdescription' => ['name' => 'ogdescription', 'type' => 'text', 'label' => ['en' => 'Description', 'de' => 'Beschreibung']],
            'intro'         => ['name' => 'intro', 'type' => 'text', 'label' => 'Intro text'],
        ], 'de');

        $this->assertSame('Beschreibung', $labels['ogdescription']);
        $this->assertSame('Intro text', $labels['intro']);
    }

    public function testFromFieldsIncludesFieldsOfGroups(): void
    {
        $labels = FieldLabels::fromFields([
            'meta' => ['type' => 'group', 'fields' => [
                'seo-title' => ['name' => 'seo-title', 'type' => 'text', 'label' => 'SEO title'],
            ]],
        ], 'en');

        $this->assertSame('SEO title', $labels['seo_title']);
    }

    public function testForModelUsesTheCurrentBlueprint(): void
    {
        $this->kirby = $this->makeApp([
            'blueprints' => [
                'pages/article' => [
                    'fields' => [
                        'ogdescription' => ['type' => 'textarea', 'label' => ['en' => 'Share description', 'de' => 'Teilen-Beschreibung']],
                    ],
                ],
            ],
            'site' => [
                'children' => [
                    ['slug' => 'test', 'template' => 'article'],
                ],
            ],
        ]);
        $this->kirby->impersonate('kirby');

        $labels = FieldLabels::forModel($this->kirby->page('test'), 'en');

        $this->assertSame('Share description', $labels['ogdescription']);
        $this->assertArrayNotHasKey('unknownfield', $labels);
    }

    public function testDiffShowsLabelAndRawKeyAndFallsBackToKeyForUnknownFields(): void
    {
        $diff = DiffGenerator::generate(
            "Ogdescription: Old text\n----\nCustom: a",
            "Ogdescription: New text\n----\nCustom: b",
            ['ogdescription' => 'Share description']
        );

        $this->assertStringContainsString('<span class="k-cw-diff-field-label">Share description</span>', $diff);
        $this->assertStringContainsString('<span class="k-cw-diff-field-key">ogdescription</span>', $diff);
        $this->assertStringContainsString('<span class="k-cw-diff-field-label">custom</span>', $diff);
    }

    public function testDiffEscapesLabels(): void
    {
        $diff = DiffGenerator::generate("Text: a", "Text: b", ['text' => '<b>Body</b>']);

        $this->assertStringContainsString('&lt;b&gt;Body&lt;/b&gt;', $diff);
    }

    public function testResolverPassesBlueprintLabels(): void
    {
        $pageDir = $this->contentDir . '/1_test';
        $this->writeContent($pageDir . '/article.txt', "Title: T\n----\nOgdescription: New\n");
        $this->writeHistory($pageDir, ['article' => [
            ['editor_id' => 'kirby', 'uuid' => 'b', 'time' => 2000000, 'version' => 2, 'content' => "Title: T\n----\nOgdescription: New\n"],
            ['editor_id' => 'kirby', 'uuid' => 'a', 'time' => 1000000, 'version' => 1, 'content' => "Title: T\n----\nOgdescription: Old\n"],
        ]]);

        $this->kirby = $this->makeApp([
            'blueprints' => [
                'pages/article' => ['fields' => ['ogdescription' => ['type' => 'text', 'label' => 'Share description']]],
            ],
        ]);
        $this->kirby->impersonate('kirby');

        $diff = (new ContentDiffResolver())->generate($pageDir, 'article', 'a', 'b');

        $this->assertStringContainsString('Share description', $diff);
        $this->assertStringContainsString('ogdescription', $diff);
    }
}
