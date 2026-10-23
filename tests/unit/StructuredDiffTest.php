<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\ContentDiffResolver;
use TearoomOne\ContentWatch\DiffGenerator;
use TearoomOne\ContentWatch\StructuredDiff;
use TearoomOne\ContentWatch\Tests\TestCase;

class StructuredDiffTest extends TestCase
{
    protected function blocks(array $blocks): string
    {
        return json_encode(array_map(fn ($b) => [
            'id'       => $b[0],
            'type'     => $b[1],
            'content'  => $b[2],
            'isHidden' => false,
        ], $blocks));
    }

    protected function statuses(array $items): array
    {
        return array_map(fn ($item) => $item['status'] . ($item['moved'] ? '+moved' : ''), $items);
    }

    public function testMatchesBlocksByIdAndDetectsAllChangeKinds(): void
    {
        $old = StructuredDiff::parseBlocks($this->blocks([
            ['a', 'heading', ['text' => 'Intro']],
            ['b', 'text', ['text' => '<p>We steep for 40 seconds.</p>']],
            ['c', 'quote', ['text' => 'Tea is nought but this']],
            ['d', 'text', ['text' => '<p>Outro</p>']],
        ]));
        $new = StructuredDiff::parseBlocks($this->blocks([
            ['a', 'heading', ['text' => 'Intro']],
            ['b', 'text', ['text' => '<p>We steep for 30 seconds.</p>']],
            ['e', 'image', ['image' => ['gaiwan-pour.jpg'], 'caption' => 'Pouring']],
            ['d', 'text', ['text' => '<p>Outro</p>']],
        ]));

        $items = StructuredDiff::diffItems($old, $new, true);

        $this->assertSame(['unchanged', 'changed', 'removed', 'added', 'unchanged'], $this->statuses($items));
        $this->assertSame(2, $items[2]['oldIndex']);
        $this->assertSame(2, $items[3]['newIndex']);
    }

    public function testMovedBlockKeepsItsIdentity(): void
    {
        $old = StructuredDiff::parseBlocks($this->blocks([
            ['a', 'text', ['text' => 'A']],
            ['b', 'text', ['text' => 'B']],
            ['c', 'text', ['text' => 'C']],
        ]));
        $new = StructuredDiff::parseBlocks($this->blocks([
            ['c', 'text', ['text' => 'C']],
            ['a', 'text', ['text' => 'A']],
            ['b', 'text', ['text' => 'B']],
        ]));

        $statuses = $this->statuses(StructuredDiff::diffItems($old, $new, true));

        $this->assertSame(['unchanged+moved', 'unchanged', 'unchanged'], $statuses);
    }

    public function testRendersBlocksWithTypePositionBadgesAndCollapsedRuns(): void
    {
        $old = $this->blocks([
            ['a', 'heading', ['text' => 'Intro']],
            ['x', 'text', ['text' => '<p>Same</p>']],
            ['b', 'text', ['text' => '<p>We steep for 40 seconds.</p>']],
            ['c', 'quote', ['text' => 'Tea is nought but this']],
        ]);
        $new = $this->blocks([
            ['a', 'heading', ['text' => 'Intro']],
            ['x', 'text', ['text' => '<p>Same</p>']],
            ['b', 'text', ['text' => '<p>We steep for 30 seconds.</p>']],
            ['e', 'image', ['image' => ['gaiwan-pour.jpg'], 'caption' => 'Pouring']],
        ]);

        $result = StructuredDiff::render($old, $new);

        $this->assertNotNull($result);
        $this->assertSame('3 of 5 blocks changed', $result['badge']);
        $html = $result['html'];
        $this->assertStringContainsString('2 unchanged blocks (Heading, Text)', $html);
        $this->assertStringContainsString('Block 3', $html);
        $this->assertStringContainsString('<del>40</del>', $html);
        $this->assertStringContainsString('<ins>30</ins>', $html);
        $this->assertStringNotContainsString('&lt;p&gt;', $html);
        $this->assertStringContainsString('was block 4', $html);
        $this->assertStringContainsString('gaiwan-pour.jpg', $html);
        $this->assertStringContainsString('k-cw-diff-badge-added', $html);
        $this->assertStringContainsString('k-cw-diff-badge-removed', $html);
    }

    public function testLayoutColumnsAreFlattenedToBlocks(): void
    {
        $layout = fn (string $text) => json_encode([[
            'id'      => 'row',
            'columns' => [
                ['id' => 'col1', 'width' => '1/2', 'blocks' => [['id' => 'a', 'type' => 'text', 'content' => ['text' => $text]]]],
                ['id' => 'col2', 'width' => '1/2', 'blocks' => [['id' => 'b', 'type' => 'heading', 'content' => ['text' => 'Side']]]],
            ],
        ]]);

        $blocks = StructuredDiff::parseBlocks($layout('Left'));
        $this->assertSame(['a', 'b'], array_column($blocks, 'id'));

        $result = StructuredDiff::render($layout('Left column'), $layout('Left side column'));
        $this->assertSame('1 of 2 blocks changed', $result['badge']);
        $this->assertStringContainsString('<ins>side </ins>', $result['html']);
    }

    public function testStructureRowsAreComparedByPosition(): void
    {
        $old = "- \n  name: Sencha\n  price: 12\n- \n  name: Bancha\n  price: 8\n";
        $new = "- \n  name: Sencha\n  price: 14\n- \n  name: Bancha\n  price: 8\n- \n  name: Hojicha\n  price: 9\n";

        $result = StructuredDiff::render($old, $new, 'structure');

        $this->assertSame('2 of 3 entries changed', $result['badge']);
        $this->assertStringContainsString('Entry 1', $result['html']);
        $this->assertStringContainsString('<li class="added">14</li>', $result['html']);
        $this->assertStringContainsString('1 unchanged entry', $result['html']);
        $this->assertStringContainsString('Hojicha', $result['html']);
    }

    public function testFallsBackToTextDiffWhenParsingFails(): void
    {
        $this->assertNull(StructuredDiff::parseBlocks('[{"no":"blocks"}]'));
        $this->assertNull(StructuredDiff::render('["red","blue"]', '["red","green"]'));
        $this->assertNull(StructuredDiff::render('[not json', '[still not json'));

        // the field still shows up as a text diff
        $result = DiffGenerator::compare('Tags: ["red","blue"]', 'Tags: ["red","green"]');
        $this->assertSame(1, $result['changed']);
        $this->assertStringContainsString('<li class="added">    &quot;green&quot;</li>', $result['html']);
        $this->assertStringNotContainsString('class="skipped"', $result['html']);
    }

    public function testCompareCountsChangedAndUnchangedFields(): void
    {
        $result = DiffGenerator::compare(
            "Title: Oolong\n----\nText: Old\n----\nIntro: Same",
            "Title: Oolong\n----\nText: New\n----\nIntro: Same\n----\nOgdescription: Added"
        );

        $this->assertSame(2, $result['changed']);
        $this->assertSame(2, $result['unchanged']);
        $this->assertStringContainsString('k-cw-diff-badge-added', $result['html']);
        $this->assertStringNotContainsString('Same', $result['html']);
    }

    public function testCompareWithCurrentContent(): void
    {
        $pageDir = $this->contentDir . '/1_test-page';
        $this->writeContent($pageDir . '/article.txt', "Title: Current title\n----\nText: Body\n");
        $this->writeHistory($pageDir, ['article' => [
            ['editor_id' => 'kirby', 'uuid' => 'new', 'time' => 2000000, 'version' => 2, 'content' => "Title: Saved title\n----\nText: Body\n"],
            ['editor_id' => 'kirby', 'uuid' => 'old', 'time' => 1000000, 'version' => 1, 'content' => "Title: Old title\n----\nText: Body\n"],
        ]]);

        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $result = (new ContentDiffResolver())->compare($pageDir, 'article', 'old', ContentDiffResolver::CURRENT);

        $this->assertSame(1, $result['changed']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertStringContainsString('<del>Old</del>', $result['html']);
        $this->assertStringContainsString('<ins>Current</ins>', $result['html']);
    }

    public function testCurrentVersionIsThePublishedFileEvenWhenChangedOutsideThePanel(): void
    {
        $pageDir = $this->contentDir . '/1_matcha';
        $saved   = "Title: Matcha\n----\nRobots: noindex, follow\n";
        $this->writeHistory($pageDir, ['article' => [
            ['editor_id' => 'kirby', 'uuid' => 'v2', 'time' => 2000000, 'version' => 2, 'language' => 'en', 'content' => $saved],
            ['editor_id' => 'kirby', 'uuid' => 'v1', 'time' => 1000000, 'version' => 1, 'language' => 'en', 'content' => "Title: Matcha\n----\nRobots: \n"],
        ]]);
        // after the last snapshot the file was reset outside the Panel (git, deploy);
        // another plugin keeps an old copy in a subfolder, which must not be read
        $this->writeContent($pageDir . '/article.en.txt', "Title: Matcha\n----\nRobots: \n");
        $this->writeContent($pageDir . '/_versions/2026-10-08/article.en.txt', $saved);
        $this->writeContent($pageDir . '/article.de.txt', "Title: Matcha DE\n----\nRobots: noindex\n");

        $this->kirby = $this->makeApp(['languages' => [
            ['code' => 'en', 'name' => 'English', 'default' => true],
            ['code' => 'de', 'name' => 'Deutsch'],
        ]]);
        $this->kirby->impersonate('kirby');

        $resolver = new ContentDiffResolver();

        // v1 matches the file on disk: nothing changed
        $this->assertSame(0, $resolver->compare($pageDir, 'article', 'v1', ContentDiffResolver::CURRENT)['changed']);

        // v2 (latest snapshot) differs from the file: robots was removed outside the Panel
        $result = $resolver->compare($pageDir, 'article', 'v2', ContentDiffResolver::CURRENT);
        $this->assertSame(1, $result['changed']);
        $this->assertStringContainsString('k-cw-diff-badge-removed', $result['html']);
        $this->assertStringContainsString('<li class="removed">noindex, follow</li>', $result['html']);
    }

    public function testReferencesInsideBlocksAndStructuresAreResolved(): void
    {
        $this->writeContent($this->contentDir . '/1_about/default.txt', "Title: About us\n----\nUuid: aboutuuid\n");
        $this->kirby = $this->makeApp();
        $this->kirby->impersonate('kirby');

        $old = $this->blocks([['a', 'sitemap', ['exclude' => 'page://aboutuuid']]]);
        $new = $this->blocks([['a', 'sitemap', ['exclude' => 'page://aboutuuid, page://gone123']]]);

        $result = DiffGenerator::compare("Settings: $old", "Settings: $new");
        $html   = $result['html'];

        $this->assertStringContainsString('About us', $html);
        $this->assertStringNotContainsString('page://aboutuuid', $html);
        $this->assertStringContainsString('<span class="k-cw-diff-ref-missing">deleted page <span class="k-cw-diff-ref-id">page://gone123</span></span>', $html);
        // the missing reference is one word in the word diff
        $this->assertMatchesRegularExpression('/<ins>[^<]*<span class="k-cw-diff-ref-missing">deleted page <span class="k-cw-diff-ref-id">page:\/\/gone123<\/span><\/span><\/ins>/', $html);

        $structure = StructuredDiff::render("- \n  link: page://gone123\n", "- \n  link: page://aboutuuid\n", 'structure');
        $this->assertStringContainsString('About us', $structure['html']);
    }

    public function testEmptyPropertiesAddedLaterAreNoChange(): void
    {
        $old = $this->blocks([['s', 'mk-openrouter', ['apikey' => 'x', 'model' => 'gemma']]]);
        $new = $this->blocks([['s', 'mk-openrouter', ['provider' => '', 'apikey' => 'x', 'endpoint' => null, 'model' => 'gemma']]]);

        $this->assertSame(0, StructuredDiff::render($old, $new)['changed']);

        $result = DiffGenerator::compare("Ai: $old\n----\nTitle: T", "Ai: $new\n----\nTitle: T");
        $this->assertSame(0, $result['changed']);
    }

    public function testSecretsAreNeverShown(): void
    {
        $old = $this->blocks([['s', 'mk-openrouter', ['apikey' => 'sk-old-secret', 'model' => 'openai/gpt-6-luna']]]);
        $new = $this->blocks([['s', 'mk-openrouter', ['apikey' => 'sk-new-secret', 'model' => 'google/gemma-4-31b-it:free']]]);

        $html = StructuredDiff::render($old, $new)['html'];

        $this->assertStringNotContainsString('sk-old-secret', $html);
        $this->assertStringNotContainsString('sk-new-secret', $html);
        $this->assertStringContainsString('value changed (hidden)', $html);
        $this->assertStringContainsString('gemma', $html);

        $field = DiffGenerator::compare("Api_token: abc\n", "Api_token: def\n")['html'];
        $this->assertStringNotContainsString('abc', $field);
        $this->assertStringContainsString('value changed (hidden)', $field);
    }
}
