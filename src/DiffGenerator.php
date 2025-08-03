<?php

namespace TearoomOne\ContentWatch;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;
use Kirby\Data\Txt;

/**
 * Class to generate diffs between content versions
 */
class DiffGenerator
{
    /**
     * Generate a visual diff between two content arrays or strings
     *
     * @param mixed $oldContent Array or string content
     * @param mixed $newContent Array or string content
     * @return string
     */
    public static function generate($oldContent, $newContent): string
    {
        // Handle both string and array content types
        if (trim($oldContent) === trim($newContent)) {
            return 'No changes found';
        }


        return self::diffStrings($oldContent, $newContent);
    }

    private static function array_value_recursive($key, array $arr): array
    {
        $val = array();
        foreach ($arr as $k => $v) {
            if ($k === $key) {
                $val[] = $arr['type'] . ': ' . json_encode($v, JSON_PRETTY_PRINT);
            } elseif (is_array($v)) {
                $val = array_merge($val, self::array_value_recursive($key, $v));
            }
        }
        return $val;
    }

    /**
     * Generate a line-by-line diff between two strings using jfcherng/php-diff
     *
     * @param string $oldStr
     * @param string $newStr
     * @return string
     */
    protected static function diffStrings(string $oldStr, string $newStr): string
    {
        $oldFields = Txt::decode($oldStr);
        $newFields = Txt::decode($newStr);

        $oldFields = self::flattenJSON($oldFields);
        $newFields = self::flattenJSON($newFields);

        $options = [
            // show how many neighbor lines
            'context' => 0,
            // ignore case difference
            'ignoreCase' => false,
            // ignore whitespace difference
            'ignoreWhitespace' => true,
        ];

        // Initialize the differ
        $differ = new Differ(array_values($oldFields), array_values($newFields), $options);

        // Create a renderer
        $renderer = RendererFactory::make('Combined', [
            'detailLevel' => 'line',
            'spacesToNbsp' => false,
            'isCli' => true,
            'separateBlock' => true,
        ]);

        // Generate and return the diff
        $result = $renderer->render($differ);

        // If no differences, return empty string
        if (trim($result) === '') {
            return '';
        }

        return $result;
    }

    /**
     * @param array $fields
     * @return array
     */
    public static function flattenJSON(array $fields): array
    {
        foreach ($fields as $key => $field) {
            // if fields is json, decode it and pretty print it
            if (strpos($field, '[') === 0) {
                unset($fields[$key]);
                $object = json_decode($field, true);
                // find array value for key 'content'
                $contents = self::array_value_recursive('content', $object);
                $index = 0;
                foreach ($contents as $content) {
                    $fields[$key . $index++] = $key . " - " . $content;
                }
            } else {
                $fields[$key] = $key . ": \n" . $field;
            }
        }
        return $fields;
    }
}
