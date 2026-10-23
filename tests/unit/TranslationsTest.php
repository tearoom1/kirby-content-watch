<?php

namespace TearoomOne\ContentWatch\Tests\Unit;

use TearoomOne\ContentWatch\Tests\TestCase;

class TranslationsTest extends TestCase
{
    /**
     * Placeholders like {count} are filled in by Kirby's $t(key, data) in the
     * Panel and by DiffGenerator::t() on the server; both languages need the same ones.
     */
    public function testEnglishAndGermanHaveTheSameKeysAndPlaceholders(): void
    {
        $source = file_get_contents(__DIR__ . '/../../index.php');
        preg_match_all("/'(tearoom1\\.content-watch\\.[a-z.]+)'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches, PREG_SET_ORDER);

        $languages = [];
        $seen      = [];
        foreach ($matches as [, $key, $text]) {
            $language = isset($seen[$key]) ? 'de' : 'en';
            $seen[$key] = true;
            $languages[$language][$key] = $text;
        }

        $this->assertNotEmpty($languages['en']);
        $this->assertSame(array_keys($languages['en']), array_keys($languages['de']));

        foreach ($languages['en'] as $key => $text) {
            preg_match_all('/\{(\w+)\}/', $text, $en);
            preg_match_all('/\{(\w+)\}/', $languages['de'][$key], $de);
            sort($en[1]);
            sort($de[1]);
            $this->assertSame($en[1], $de[1], $key);
        }
    }
}
