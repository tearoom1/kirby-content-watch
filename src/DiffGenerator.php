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
        // check if package is installed
        $useAdvancedDiff = class_exists('Jfcherng\Diff\Differ');

        $oldFields = self::flattenJSON(Txt::decode($oldContent));
        $newFields = self::flattenJSON(Txt::decode($newContent));

        $allKeys = array_unique(array_merge(array_keys($oldFields), array_keys($newFields)));

        $oldValues = [];
        $newValues = [];
        foreach ($allKeys as $key) {
            $old = $oldFields[$key] ?? '';
            $new = $newFields[$key] ?? '';
            if ($old !== $new) {
                if ($useAdvancedDiff) {
                    $oldValues = array_merge($oldValues, explode("\n", $old));
                    $newValues = array_merge($newValues, explode("\n", $new));
                } else {
                    $oldValues[] = $old;
                    $newValues[] = $new;
                }
            }
        }

        if (!$useAdvancedDiff) {
            return self::diffStringsSimple($oldValues, $newValues);
        }

        return self::diffStringsWithDiffer($oldValues, $newValues);
    }

    /**
     * Generate a line-by-line diff between two strings
     *
     * @param string $oldStr
     * @param string $newStr
     * @return string
     */
    private static function diffStringsSimple(array $oldLines, array $newLines): string
    {
        $output = '';
        $changes = false;

        for ($i = 0; $i < count($oldLines); $i++) {
            $oldLine = $oldLines[$i];
            $newLine = $newLines[$i];

            $oldLine = htmlentities($oldLine);
            $newLine = htmlentities($newLine);

            if ($oldLine !== $newLine) {

                // Split lines into words and highlight differences
                $oldWords = preg_split('/\s+/', $oldLine);
                $newWords = preg_split('/\s+/', $newLine);

                // Find common words at the beginning
                $startCommon = 0;
                $minLength = min(count($oldWords), count($newWords));
                while ($startCommon < $minLength && $oldWords[$startCommon] === $newWords[$startCommon]) {
                    $startCommon++;
                }

                // Find common words at the end
                $oldEnd = count($oldWords) - 1;
                $newEnd = count($newWords) - 1;
                $endCommon = 0;
                while ($endCommon < $minLength - $startCommon &&
                    $oldEnd - $endCommon >= $startCommon &&
                    $newEnd - $endCommon >= $startCommon &&
                    $oldWords[$oldEnd - $endCommon] === $newWords[$newEnd - $endCommon]) {
                    $endCommon++;
                }

                // Highlight the differences
                $oldLine = '';
                $newLine = '';

                // Add common prefix
                for ($j = 0; $j < $startCommon; $j++) {
                    $oldLine .= $oldWords[$j] . ' ';
                    $newLine .= $newWords[$j] . ' ';
                }

                $oldLine .= '<span class="diff-delete">';
                // Add highlighted differences
                $oldHighContent = '';
                for ($j = $startCommon; $j <= $oldEnd - $endCommon; $j++) {
                    $oldHighContent .= $oldWords[$j] . ' ';
                }
                $oldLine .= trim($oldHighContent) . '</span> ';
                $newLine .= '<span class="diff-add">';
                $newHighContent = '';
                for ($j = $startCommon; $j <= $newEnd - $endCommon; $j++) {
                    $newHighContent .= $newWords[$j] . ' ';
                }
                $newLine .= trim($newHighContent) . '</span> ';

                // Add common suffix
                for ($j = 0; $j < $endCommon; $j++) {
                    $oldLine .= $oldWords[$oldEnd - $endCommon + 1 + $j] . ' ';
                    $newLine .= $newWords[$newEnd - $endCommon + 1 + $j] . ' ';
                }

                $oldLine = trim($oldLine);
                $newLine = trim($newLine);

                $diffLine = '';
                if ($oldLine !== '') {
                    $diffLine .= "<li class='removed'>{$oldLine}</li>";
                }

                if ($newLine !== '') {
                    $diffLine .= "<li class='added'>{$newLine}</li>";
                }
                $changes = true;

                if (!empty($diffLine)) {
                    $output .= '<ul>';
                    $output .= $diffLine;
                    $output .= '</ul><hr/>';
                }
            }
        }

        return $changes ? $output : '';
    }

    /**
     * Generate a line-by-line diff between two strings using jfcherng/php-diff
     *
     * @param string $oldStr
     * @param string $newStr
     * @return string
     */
    protected static function diffStringsWithDiffer(array $oldValues, array $newValues): string
    {

        $options = [
            // show how many neighbor lines
            'context' => 1,
            // ignore case difference
            'ignoreCase' => false,
            // ignore whitespace difference
            'ignoreWhitespace' => true,
        ];

        // Initialize the differ
        $differ = new Differ($oldValues, $newValues, $options);

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
                foreach ($contents as $id => $content) {
                    $fields[$key . $id] = $key . " - " . $content;
                }
            } else {
                $fields[$key] = $key . ": \n" . $field;
            }
        }
        return $fields;
    }

    private static function array_value_recursive($key, array $arr): array
    {
        $val = array();
        foreach ($arr as $k => $v) {
            if ($k === $key) {
                $val[$arr['id']] = $arr['type'] . ': ' . json_encode($v, JSON_PRETTY_PRINT);
            } elseif (is_array($v)) {
                $val = array_merge($val, self::array_value_recursive($key, $v));
            }
        }
        return $val;
    }
}
