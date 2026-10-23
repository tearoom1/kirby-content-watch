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
     * @param array<string, string> $labels field key => readable label, see FieldLabels
     * @return string
     */
    public static function generate($oldContent, $newContent, array $labels = []): string
    {
        // Handle both string and array content types
        if (trim($oldContent) === trim($newContent)) {
            return 'No changes found';
        }
        // check if package is installed
        $useAdvancedDiff = class_exists('Jfcherng\Diff\Differ');

        $oldFields = self::flattenFields(Txt::decode($oldContent));
        $newFields = self::flattenFields(Txt::decode($newContent));

        $allKeys = array_unique(array_merge(array_keys($oldFields), array_keys($newFields)));

        $changes = [];
        foreach ($allKeys as $key) {
            $old = $oldFields[$key]['value'] ?? '';
            $new = $newFields[$key]['value'] ?? '';
            if ($old !== $new) {
                $changes[] = [
                    'field' => $oldFields[$key]['field'] ?? $newFields[$key]['field'],
                    'sub'   => $oldFields[$key]['sub'] ?? $newFields[$key]['sub'] ?? null,
                    'old'   => $old,
                    'new'   => $new,
                ];
            }
        }

        // Fallback: if structured TXT decoding produced no field deltas but the composed
        // snapshots still differ, diff the raw lines so meta-only changes stay visible.
        if ($changes === []) {
            $changes[] = [
                'field' => null,
                'sub'   => null,
                'old'   => trim($oldContent),
                'new'   => trim($newContent),
            ];
        }

        if ($useAdvancedDiff) {
            $oldValues = [];
            $newValues = [];
            foreach ($changes as $change) {
                $prefix    = $change['field'] !== null ? self::fieldPrefix($change) : '';
                $oldValues = array_merge($oldValues, explode("\n", $change['old'] === '' ? '' : $prefix . $change['old']));
                $newValues = array_merge($newValues, explode("\n", $change['new'] === '' ? '' : $prefix . $change['new']));
            }

            return self::diffStringsWithDiffer($oldValues, $newValues);
        }

        $output = '';
        foreach ($changes as $change) {
            $output .= self::renderField($change, $labels);
        }

        return '<div class="k-cw-diff">' . $output . '</div>';
    }

    /**
     * One changed field: its label (with the raw key) and the changed lines
     */
    protected static function renderField(array $change, array $labels): string
    {
        $header = '';

        if ($change['field'] !== null) {
            $key   = $change['field'];
            $label = $labels[$key] ?? null;
            $raw   = $key . ($change['sub'] ? ' · ' . $change['sub'] : '');

            $header = '<div class="k-cw-diff-field-header">';
            if (is_string($label) && $label !== '') {
                $header .= '<span class="k-cw-diff-field-label">' . self::escape($label) . '</span> ';
                $header .= '<span class="k-cw-diff-field-key">' . self::escape($raw) . '</span>';
            } else {
                // unknown field: the raw key is all we have
                $header .= '<span class="k-cw-diff-field-label">' . self::escape($raw) . '</span>';
            }
            $header .= '</div>';
        }

        return '<div class="k-cw-diff-field">'
            . $header
            . '<ul class="k-cw-diff-lines">' . self::renderValueChange($change['old'], $change['new']) . '</ul>'
            . '</div>';
    }

    /**
     * Old and new value of a field, the changed words in between marked
     */
    protected static function renderValueChange(string $old, string $new): string
    {
        $oldLine = self::escape($old);
        $newLine = self::escape($new);

        if ($old === '' || $new === '') {
            $lines = '';
            if ($old !== '') {
                $lines .= '<li class="removed">' . $oldLine . '</li>';
            }
            if ($new !== '') {
                $lines .= '<li class="added">' . $newLine . '</li>';
            }

            return $lines;
        }

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

        $prefix = implode(' ', array_slice($oldWords, 0, $startCommon));
        $suffix = implode(' ', array_slice($oldWords, $oldEnd - $endCommon + 1));
        $oldMid = implode(' ', array_slice($oldWords, $startCommon, $oldEnd - $endCommon - $startCommon + 1));
        $newMid = implode(' ', array_slice($newWords, $startCommon, $newEnd - $endCommon - $startCommon + 1));

        $oldLine = trim($prefix . ' ' . ($oldMid !== '' ? '<span class="diff-delete">' . $oldMid . '</span>' : '') . ' ' . $suffix);
        $newLine = trim($prefix . ' ' . ($newMid !== '' ? '<span class="diff-add">' . $newMid . '</span>' : '') . ' ' . $suffix);

        return '<li class="removed">' . $oldLine . '</li>'
            . '<li class="added">' . $newLine . '</li>';
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
     * Content fields as readable `key: value` strings,
     * blocks and layouts split into one entry per block
     *
     * @param array $fields
     * @return array
     */
    public static function flattenJSON(array $fields): array
    {
        return array_map(
            fn (array $entry) => self::fieldPrefix([
                'field' => $entry['field'],
                'sub'   => $entry['sub'],
                'old'   => $entry['value'],
                'new'   => '',
            ]) . $entry['value'],
            self::flattenFields($fields)
        );
    }

    /**
     * Content fields with their key, the block type (if any) and the value to compare
     *
     * @return array<string, array{field: string, sub: ?string, value: string}>
     */
    public static function flattenFields(array $fields): array
    {
        $flat = [];

        foreach ($fields as $key => $field) {
            $key = (string)$key;

            // if fields is json, decode it and pretty print it
            if (is_string($field) && strpos($field, '[') === 0) {
                // Use JSON_UNESCAPED_UNICODE flag to properly decode UTF-8 characters
                $object = json_decode($field, true, 512, JSON_UNESCAPED_UNICODE);
                if ($object === null && json_last_error() !== JSON_ERROR_NONE) {
                    // Fallback: try to manually decode Unicode escape sequences
                    $field = self::decodeUnicodeEscapes($field);
                    $object = json_decode($field, true, 512, JSON_UNESCAPED_UNICODE);
                }

                // Not valid JSON (e.g. a text starting with a Markdown link): treat as plain text
                if (!is_array($object)) {
                    $flat[$key] = ['field' => $key, 'sub' => null, 'value' => $field];
                    continue;
                }

                // find array value for key 'content' (blocks/layouts)
                $contents = self::array_value_recursive('content', $object);
                if ($contents === []) {
                    // JSON without blocks: show the whole pretty-printed value
                    $flat[$key] = ['field' => $key, 'sub' => null, 'value' => self::jsonEncodeFormatted($object)];
                    continue;
                }
                foreach ($contents as $id => $content) {
                    $flat[$key . $id] = ['field' => $key, 'sub' => $content['type'], 'value' => $content['value']];
                }
            } else {
                $value = is_scalar($field) || $field === null ? (string)$field : self::jsonEncodeFormatted($field);
                $flat[$key] = ['field' => $key, 'sub' => null, 'value' => $value];
            }
        }

        return $flat;
    }

    /**
     * `key: ` or `key - blocktype: ` in front of a value, multi-line values start on the next line
     */
    protected static function fieldPrefix(array $change): string
    {
        $prefix = $change['field'] . ($change['sub'] ? ' - ' . $change['sub'] : '');

        return str_contains($change['old'] . $change['new'], "\n")
            ? $prefix . ":\n"
            : $prefix . ': ';
    }

    protected static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function array_value_recursive($key, array $arr): array
    {
        $val = array();
        foreach ($arr as $k => $v) {
            if ($k === $key) {
                // Use JSON_PRETTY_PRINT and JSON_UNESCAPED_UNICODE for proper UTF-8 output
                $id = $arr['id'] ?? md5(self::jsonEncodeFormatted($arr));
                $val[$id] = [
                    'type'  => (string)($arr['type'] ?? $key),
                    'value' => self::jsonEncodeFormatted($v),
                ];
            } elseif (is_array($v)) {
                $val = array_merge($val, self::array_value_recursive($key, $v));
            }
        }
        return $val;
    }

    /**
     * Encode JSON with pretty printing and proper UTF-8 handling
     *
     * @param mixed $data Data to encode
     * @return string Formatted JSON string
     */
    private static function jsonEncodeFormatted($data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Manually decode Unicode escape sequences in a string
     *
     * @param string $str String with Unicode escape sequences
     * @return string String with decoded UTF-8 characters
     */
    private static function decodeUnicodeEscapes(string $str): string
    {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
            return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
        }, $str);
    }
}
