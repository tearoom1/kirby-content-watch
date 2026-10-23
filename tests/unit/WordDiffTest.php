<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\DiffGenerator;
use TearoomOne\ContentWatch\Tests\TestCase;

class WordDiffTest extends TestCase
{
    public function testMarksOnlyTheChangedWord(): void
    {
        [$old, $new] = DiffGenerator::wordDiff('The quick brown fox jumps', 'The quick red fox jumps');

        $this->assertSame('The quick <del>brown</del> fox jumps', $old);
        $this->assertSame('The quick <ins>red</ins> fox jumps', $new);
    }

    public function testMarksSeveralSeparateChanges(): void
    {
        [$old, $new] = DiffGenerator::wordDiff('one two three four five six', 'one 2 three four five 6');

        $this->assertSame('one <del>two</del> three four five <del>six</del>', $old);
        $this->assertSame('one <ins>2</ins> three four five <ins>6</ins>', $new);
    }

    public function testJoinsNeighbouringChangedWordsIntoOneMark(): void
    {
        [$old, $new] = DiffGenerator::wordDiff('keep alpha beta keep', 'keep gamma delta keep');

        $this->assertSame('keep <del>alpha beta</del> keep', $old);
        $this->assertSame('keep <ins>gamma delta</ins> keep', $new);
    }

    public function testInsertedWordOnlyMarksTheNewSide(): void
    {
        [$old, $new] = DiffGenerator::wordDiff('Hello world', 'Hello brave world');

        $this->assertSame('Hello world', $old);
        $this->assertStringContainsString('<ins>brave </ins>', $new);
    }

    public function testEscapesHtml(): void
    {
        [$old, $new] = DiffGenerator::wordDiff('a <b>bold</b> text', 'a <b>strong</b> text');

        $this->assertStringContainsString('&lt;b&gt;<del>bold</del>&lt;/b&gt;', $old);
        $this->assertStringContainsString('&lt;b&gt;<ins>strong</ins>&lt;/b&gt;', $new);
    }

    public function testReturnsNullForUnrelatedLines(): void
    {
        $this->assertNull(DiffGenerator::wordDiff('Completely different', 'Nothing alike here at all'));
    }

    public function testChangedLinePairUsesInlineMarks(): void
    {
        $diff = DiffGenerator::generate('Text: The quick brown fox', 'Text: The quick red fox');

        $this->assertStringContainsString('<li class="removed inline">The quick <del>brown</del> fox</li>', $diff);
        $this->assertStringContainsString('<li class="added inline">The quick <ins>red</ins> fox</li>', $diff);
    }

    public function testPureAdditionKeepsFullLineStyle(): void
    {
        $diff = DiffGenerator::generate('Title: Hello', "Title: Hello\n----\nText: New text here");

        $this->assertStringContainsString('<li class="added">New text here</li>', $diff);
        $this->assertStringNotContainsString('class="removed', $diff);
    }

    public function testLongTextOnlyShowsChangedLinesWithContext(): void
    {
        $lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = 'Paragraph number ' . $i . ' stays the same.';
        }
        $old = implode("\n", $lines);
        $lines[9] = 'Paragraph number 10 was edited a little.';
        $new = implode("\n", $lines);

        $diff = DiffGenerator::generate("Text:\n\n" . $old, "Text:\n\n" . $new);

        $this->assertStringContainsString('<li class="context">Paragraph number 9 stays the same.</li>', $diff);
        $this->assertStringContainsString('<li class="context">Paragraph number 11 stays the same.</li>', $diff);
        $this->assertStringNotContainsString('Paragraph number 3 ', $diff);
        $this->assertStringContainsString('<li class="skipped">…</li>', $diff);
        $this->assertStringContainsString('<del>stays the same</del>', $diff);
    }
}
