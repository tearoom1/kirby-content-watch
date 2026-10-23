<?php

namespace TearoomOne\ContentWatch;

use Jfcherng\Diff\Differ;
use Jfcherng\Diff\Factory\RendererFactory;
use Kirby\Data\Txt;
use Kirby\Toolkit\I18n;

/**
 * Class to generate diffs between content versions
 */
class DiffGenerator
{
    /** Size limits of the LCS tables (rows × columns) */
    protected const MAX_LINE_CELLS = 250000;
    protected const MAX_WORD_CELLS = 250000;

    /** Share of unchanged characters a line pair needs for inline word marks */
    protected const MIN_WORD_SIMILARITY = 0.3;

    /**
     * Field by field comparison of two snapshots for the diff dialog
     *
     * Unchanged fields are left out and only counted. Blocks, layouts and
     * structure fields are compared item by item (see StructuredDiff),
     * everything else line by line with the changed words marked.
     *
     * @param array<string, string> $labels field key => label, see FieldLabels
     * @param array<string, string> $types  field key => blueprint field type
     * @return array{html: string, changed: int, unchanged: int}
     */
    public static function compare(string $oldContent, string $newContent, array $labels = [], array $types = []): array
    {
        if (trim($oldContent) === trim($newContent)) {
            return ['html' => '', 'changed' => 0, 'unchanged' => count(Txt::decode($newContent))];
        }

        $oldFields = Txt::decode($oldContent);
        $newFields = Txt::decode($newContent);
        $allKeys   = array_unique(array_merge(array_keys($oldFields), array_keys($newFields)));

        $html      = '';
        $changed   = 0;
        $unchanged = 0;

        foreach ($allKeys as $key) {
            $key = (string)$key;
            $old = self::stringValue($oldFields[$key] ?? '');
            $new = self::stringValue($newFields[$key] ?? '');

            if ($old === $new) {
                $unchanged++;
                continue;
            }

            $field = self::renderComparedField($key, $old, $new, $labels, $types[$key] ?? null);

            // only the JSON formatting differs
            if ($field === null) {
                $unchanged++;
                continue;
            }

            $changed++;
            $html .= $field;
        }

        // structured decoding found no field deltas, but the snapshots differ
        if ($changed === 0) {
            $changed = 1;
            $html   .= self::renderField(['field' => null, 'sub' => null, 'old' => trim($oldContent), 'new' => trim($newContent)], $labels);
        }

        return [
            'html'      => '<div class="k-cw-diff">' . $html . '</div>',
            'changed'   => $changed,
            'unchanged' => $unchanged,
        ];
    }

    protected static function renderComparedField(string $key, string $old, string $new, array $labels, ?string $type): ?string
    {
        if (in_array($type, ['files', 'pages', 'users'], true)) {
            $oldNames = StructuredDiff::referencesToText($old, $type);
            $newNames = StructuredDiff::referencesToText($new, $type);
            if ($oldNames !== null && $newNames !== null) {
                $old = $oldNames;
                $new = $newNames;
            }
        }

        $status = match (true) {
            trim($old) === '' => 'added',
            trim($new) === '' => 'removed',
            default           => 'changed',
        };

        $structured = null;
        try {
            $structured = StructuredDiff::render($old, $new, $type);
        } catch (\Throwable) {
            // unexpected data: fall back to the text diff below
        }

        if ($structured !== null) {
            if ($structured['changed'] === 0) {
                return null;
            }

            return self::renderFieldWrapper($key, null, $labels, $structured['badge'], 'changed', $structured['html']);
        }

        // text diff; JSON values without blocks may still split into several parts
        $oldParts = self::flattenFields([$key => $old]);
        $newParts = self::flattenFields([$key => $new]);
        $body     = '';

        foreach (array_unique(array_merge(array_keys($oldParts), array_keys($newParts))) as $part) {
            $o = $oldParts[$part]['value'] ?? '';
            $n = $newParts[$part]['value'] ?? '';
            if ($o === $n) {
                continue;
            }
            $sub   = $oldParts[$part]['sub'] ?? $newParts[$part]['sub'] ?? null;
            $body .= ($sub ? '<div class="k-cw-diff-sub">' . self::escape($sub) . '</div>' : '')
                . '<ul class="k-cw-diff-lines">' . self::renderValueChange($o, $n) . '</ul>';
        }

        return self::renderFieldWrapper($key, null, $labels, self::t('diff.' . $status, $status), $status, $body);
    }

    protected static function renderFieldWrapper(string $key, ?string $sub, array $labels, string $badge, string $status, string $body): string
    {
        $label = $labels[$key] ?? null;
        $raw   = $key . ($sub ? ' · ' . $sub : '');

        $header = '<div class="k-cw-diff-field-header">';
        if (is_string($label) && $label !== '') {
            $header .= '<span class="k-cw-diff-field-label">' . self::escape($label) . '</span> ';
            $header .= '<span class="k-cw-diff-field-key">' . self::escape($raw) . '</span>';
        } else {
            $header .= '<span class="k-cw-diff-field-label">' . self::escape($raw) . '</span>';
        }
        $header .= ' ' . self::badge($badge, $status) . '</div>';

        return '<div class="k-cw-diff-field">' . $header . $body . '</div>';
    }

    public static function badge(string $text, string $status): string
    {
        return '<span class="k-cw-diff-badge k-cw-diff-badge-' . self::escape($status) . '">' . self::escape($text) . '</span>';
    }

    /**
     * Translated string of the plugin in the Panel language, `{name}` placeholders replaced
     */
    public static function t(string $key, string $fallback, array $data = []): string
    {
        $text = I18n::translate('tearoom1.content-watch.' . $key, $fallback, FieldLabels::locale());
        $text = is_string($text) ? $text : $fallback;

        foreach ($data as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }

        return $text;
    }

    protected static function stringValue(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string)$value : self::jsonEncodeFormatted($value);
    }

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
     * Lines of a field value. Changed lines that pair up (one removed, one added)
     * get the changed words marked, pure additions and removals keep the
     * full-line style. Unchanged lines show as context next to a change only.
     */
    public static function renderValueChange(string $old, string $new): string
    {
        $oldLines = $old === '' ? [] : explode("\n", $old);
        $newLines = $new === '' ? [] : explode("\n", $new);

        $ops    = self::diffSequences($oldLines, $newLines, self::MAX_LINE_CELLS);
        $count  = count($ops);
        $output = '';
        $skipped = false;

        // unchanged lines next to a change stay visible as context
        $visible = [];
        foreach ($ops as $i => [$type]) {
            $visible[$i] = $type !== '=' || ($ops[$i - 1][0] ?? '=') !== '=' || ($ops[$i + 1][0] ?? '=') !== '=';
        }
        // a single hidden line takes as much room as the "…" marker: show it instead
        foreach ($visible as $i => $isVisible) {
            if (!$isVisible && ($visible[$i - 1] ?? true) && ($visible[$i + 1] ?? true)) {
                $visible[$i] = true;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            [$type] = $ops[$i];

            if ($type === '=') {
                if ($visible[$i]) {
                    $output .= '<li class="context">' . self::escape($ops[$i][1]) . '</li>';
                    $skipped = false;
                } elseif (!$skipped) {
                    $output .= '<li class="skipped">…</li>';
                    $skipped = true;
                }
                continue;
            }

            // collect the whole hunk of removed and added lines
            $removed = [];
            $added   = [];
            while ($i < $count && $ops[$i][0] !== '=') {
                if ($ops[$i][0] === '-') {
                    $removed[] = $ops[$i][1];
                } else {
                    $added[] = $ops[$i][1];
                }
                $i++;
            }
            $i--;

            $pairs = min(count($removed), count($added));
            $removedHtml = [];
            $addedHtml   = [];

            foreach ($removed as $index => $line) {
                $words = $index < $pairs ? self::wordDiff($line, $added[$index]) : null;
                if ($words !== null) {
                    $removedHtml[] = '<li class="removed inline">' . $words[0] . '</li>';
                    $addedHtml[$index] = '<li class="added inline">' . $words[1] . '</li>';
                } else {
                    $removedHtml[] = '<li class="removed">' . self::escape($line) . '</li>';
                }
            }

            foreach ($added as $index => $line) {
                $addedHtml[$index] ??= '<li class="added">' . self::escape($line) . '</li>';
            }
            ksort($addedHtml);

            $output .= implode('', $removedHtml) . implode('', $addedHtml);
            $skipped = false;
        }

        return $output;
    }

    /**
     * Word-level diff of two versions of a line
     *
     * Returns the escaped old and new line with the changed words wrapped in
     * `<del>` and `<ins>`, or null when the lines have too little in common
     * for word marks to help (then both lines are shown as a whole).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function wordDiff(string $old, string $new): ?array
    {
        $oldTokens = self::tokenize($old);
        $newTokens = self::tokenize($new);
        $ops       = self::diffSequences($oldTokens, $newTokens, self::MAX_WORD_CELLS);

        // whitespace between two changes belongs to the change, keeps marks in one piece
        $count = count($ops);
        for ($i = 1; $i < $count - 1; $i++) {
            if ($ops[$i][0] === '=' && trim($ops[$i][1]) === '' && $ops[$i - 1][0] !== '=' && $ops[$i + 1][0] !== '=') {
                $ops[$i] = ['~', $ops[$i][1]];
            }
        }

        $common = 0;
        foreach ($ops as [$type, $token]) {
            if ($type === '=' && trim($token) !== '') {
                $common += mb_strlen($token);
            }
        }

        $longest = max(mb_strlen(preg_replace('/\s+/u', '', $old)), mb_strlen(preg_replace('/\s+/u', '', $new)));
        if ($longest === 0 || $common / $longest < self::MIN_WORD_SIMILARITY) {
            return null;
        }

        $oldHtml = '';
        $newHtml = '';
        $oldMark = '';
        $newMark = '';

        $flush = function () use (&$oldHtml, &$newHtml, &$oldMark, &$newMark) {
            if ($oldMark !== '') {
                $oldHtml .= '<del>' . self::escape($oldMark) . '</del>';
            }
            if ($newMark !== '') {
                $newHtml .= '<ins>' . self::escape($newMark) . '</ins>';
            }
            $oldMark = $newMark = '';
        };

        foreach ($ops as [$type, $token]) {
            switch ($type) {
                case '=':
                    $flush();
                    $oldHtml .= self::escape($token);
                    $newHtml .= self::escape($token);
                    break;
                case '~':
                    $oldMark .= $token;
                    $newMark .= $token;
                    break;
                case '-':
                    $oldMark .= $token;
                    break;
                case '+':
                    $newMark .= $token;
                    break;
            }
        }
        $flush();

        return [$oldHtml, $newHtml];
    }

    /**
     * Words, runs of whitespace and single other characters (punctuation)
     */
    protected static function tokenize(string $line): array
    {
        preg_match_all('/[\p{L}\p{N}_]+|\s+|./us', $line, $matches);

        return $matches[0];
    }

    /**
     * Longest common subsequence diff of two lists
     *
     * Common head and tail are trimmed first. When the rest is too large for
     * the LCS table, the middle is reported as removed and added as a whole.
     *
     * @return array<int, array{0: string, 1: string}> ['=', item], ['-', item] or ['+', item]
     */
    public static function diffSequences(array $old, array $new, int $maxCells = self::MAX_LINE_CELLS): array
    {
        $head = [];
        while ($old !== [] && $new !== [] && $old[0] === $new[0]) {
            $head[] = ['=', array_shift($old)];
            array_shift($new);
        }

        $tail = [];
        while ($old !== [] && $new !== [] && end($old) === end($new)) {
            array_unshift($tail, ['=', array_pop($old)]);
            array_pop($new);
        }

        $n = count($old);
        $m = count($new);
        $middle = [];

        if ($n * $m > $maxCells) {
            foreach ($old as $item) {
                $middle[] = ['-', $item];
            }
            foreach ($new as $item) {
                $middle[] = ['+', $item];
            }

            return [...$head, ...$middle, ...$tail];
        }

        // lengths[i][j] = LCS length of old[i..] and new[j..]
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $middle[] = ['=', $old[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $middle[] = ['-', $old[$i++]];
            } else {
                $middle[] = ['+', $new[$j++]];
            }
        }
        while ($i < $n) {
            $middle[] = ['-', $old[$i++]];
        }
        while ($j < $m) {
            $middle[] = ['+', $new[$j++]];
        }

        return [...$head, ...$middle, ...$tail];
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

    public static function escape(string $value): string
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
    public static function jsonEncodeFormatted($data): string
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
