<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\Blueprint;
use Kirby\Data\Data;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Str;
use Kirby\Uuid\Uuid;
use Throwable;

/**
 * Item by item diff of blocks, layouts and structure fields
 *
 * Blocks (and the blocks inside layout columns) are matched by their id,
 * structure entries by position. Each changed item lists its changed
 * properties with the changed words marked, unchanged runs collapse
 * to one line.
 */
class StructuredDiff
{
    protected static array $blockBlueprints = [];

    /**
     * @return array{html: string, badge: string, changed: int}|null null when the values are no item lists
     */
    public static function render(string $old, string $new, ?string $type = null): ?array
    {
        if ($type === 'structure') {
            $oldRows = self::parseStructure($old);
            $newRows = self::parseStructure($new);

            if ($oldRows === null || $newRows === null) {
                return null;
            }

            return self::renderItems(self::diffItems($oldRows, $newRows, false), 'rows');
        }

        $oldBlocks = self::parseBlocks($old);
        $newBlocks = self::parseBlocks($new);

        if ($oldBlocks === null || $newBlocks === null || ($oldBlocks === [] && $newBlocks === [])) {
            return null;
        }

        return self::renderItems(self::diffItems($oldBlocks, $newBlocks, true), 'blocks');
    }

    /**
     * Blocks of a blocks or layout field, or null for anything else
     *
     * @return array<int, array{id: ?string, type: string, content: array}>|null
     */
    public static function parseBlocks(string $value): ?array
    {
        if (trim($value) === '') {
            return [];
        }

        $data = json_decode($value, true);
        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }

        $blocks = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                return null;
            }

            // layout row: collect the blocks of all columns
            if (isset($item['columns']) && is_array($item['columns'])) {
                foreach ($item['columns'] as $column) {
                    foreach (($column['blocks'] ?? []) as $block) {
                        $parsed = self::parseBlock($block);
                        if ($parsed === null) {
                            return null;
                        }
                        $blocks[] = $parsed;
                    }
                }
                continue;
            }

            $parsed = self::parseBlock($item);
            if ($parsed === null) {
                return null;
            }
            $blocks[] = $parsed;
        }

        return $blocks;
    }

    protected static function parseBlock(mixed $block): ?array
    {
        if (!is_array($block) || !is_string($block['type'] ?? null) || !is_array($block['content'] ?? null)) {
            return null;
        }

        $content = $block['content'];
        // hiding a block is a change of the block as well
        if (($block['isHidden'] ?? false) === true) {
            $content['isHidden'] = true;
        }

        return [
            'id'      => is_string($block['id'] ?? null) && $block['id'] !== '' ? $block['id'] : null,
            'type'    => $block['type'],
            'content' => $content,
        ];
    }

    /**
     * Entries of a structure field (YAML), or null when it is no list of entries
     */
    public static function parseStructure(string $value): ?array
    {
        if (trim($value) === '') {
            return [];
        }

        try {
            $data = Data::decode($value, 'yaml');
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || !array_is_list($data)) {
            return null;
        }

        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row) || array_is_list($row)) {
                return null;
            }
            $rows[] = ['id' => null, 'type' => null, 'content' => $row];
        }

        return $rows;
    }

    /**
     * Match old and new items: blocks by id, entries without id by position
     *
     * @return array<int, array{status: string, old: ?array, new: ?array, oldIndex: ?int, newIndex: ?int, moved: bool}>
     */
    public static function diffItems(array $old, array $new, bool $matchIds): array
    {
        $key = function (array $item, int $index) use ($matchIds) {
            return $matchIds && $item['id'] !== null
                ? 'id:' . $item['id']
                : 'value:' . json_encode([$item['type'], $item['content']]);
        };

        $oldKeys = array_map($key, $old, array_keys($old));
        $newKeys = array_map($key, $new, array_keys($new));

        $oldIndex = array_flip(array_reverse($oldKeys, true));
        $newIndex = array_flip(array_reverse($newKeys, true));

        $ops    = DiffGenerator::diffSequences($oldKeys, $newKeys);
        $result = [];
        $o      = 0;
        $n      = 0;
        $hunk   = ['-' => [], '+' => []];

        $flush = function () use (&$hunk, &$result, $old, $new) {
            // removed and added entries side by side are changes of the same entry
            $pairs = min(count($hunk['-']), count($hunk['+']));
            for ($i = 0; $i < max(count($hunk['-']), count($hunk['+'])); $i++) {
                $oi = $hunk['-'][$i] ?? null;
                $ni = $hunk['+'][$i] ?? null;
                if ($i < $pairs && $old[$oi]['id'] === null && $old[$oi]['type'] === $new[$ni]['type']) {
                    $result[] = ['status' => 'changed', 'old' => $old[$oi], 'new' => $new[$ni], 'oldIndex' => $oi, 'newIndex' => $ni, 'moved' => false];
                    continue;
                }
                if ($oi !== null) {
                    $result[] = ['status' => 'removed', 'old' => $old[$oi], 'new' => null, 'oldIndex' => $oi, 'newIndex' => null, 'moved' => false];
                }
                if ($ni !== null) {
                    $result[] = ['status' => 'added', 'old' => null, 'new' => $new[$ni], 'oldIndex' => null, 'newIndex' => $ni, 'moved' => false];
                }
            }
            $hunk = ['-' => [], '+' => []];
        };

        foreach ($ops as [$op, $itemKey]) {
            if ($op === '=') {
                $flush();
                $result[] = self::matched($old[$o], $new[$n], $o, $n, false);
                $o++;
                $n++;
                continue;
            }

            if ($op === '-') {
                // block that still exists further down or up: shown where it is now
                if (!isset($newIndex[$itemKey]) || !str_starts_with($itemKey, 'id:')) {
                    $hunk['-'][] = $o;
                }
                $o++;
                continue;
            }

            if (isset($oldIndex[$itemKey]) && str_starts_with($itemKey, 'id:')) {
                $flush();
                $result[] = self::matched($old[$oldIndex[$itemKey]], $new[$n], $oldIndex[$itemKey], $n, true);
            } else {
                $hunk['+'][] = $n;
            }
            $n++;
        }
        $flush();

        return $result;
    }

    protected static function matched(array $old, array $new, int $oldIndex, int $newIndex, bool $moved): array
    {
        $same = $old['type'] === $new['type'] && self::normalize($old['content']) == self::normalize($new['content']);

        return [
            'status'   => $same ? 'unchanged' : 'changed',
            'old'      => $old,
            'new'      => $new,
            'oldIndex' => $oldIndex,
            'newIndex' => $newIndex,
            'moved'    => $moved,
        ];
    }

    /**
     * Content without empty values: a property added later with an empty
     * value (e.g. after a blueprint update) is no change
     */
    protected static function normalize(array $content): array
    {
        return array_filter($content, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * Passwords, API keys, tokens and secrets are never shown in the diff
     */
    public static function isSecret(string $name, ?string $type = null): bool
    {
        return $type === 'password'
            || preg_match('/api.?key|secret|token|passw(or)?d/i', $name) === 1;
    }

    /**
     * Line for a changed secret value
     */
    public static function hiddenChange(): string
    {
        return '<li class="hidden-change">' . DiffGenerator::escape(DiffGenerator::t('diff.hidden', 'value changed (hidden)')) . '</li>';
    }

    /**
     * @param string $kind `blocks` or `rows`
     */
    protected static function renderItems(array $items, string $kind): array
    {
        $html     = '';
        $run      = [];
        $changed  = 0;

        $flushRun = function () use (&$run, &$html, $kind) {
            if ($run === []) {
                return;
            }
            $count = count($run);
            if ($kind === 'blocks') {
                $types = array_values(array_unique(array_map(fn ($item) => self::blockName($item['new']['type']), $run)));
                $text  = DiffGenerator::t(
                    $count === 1 ? 'diff.blocks.unchanged.one' : 'diff.blocks.unchanged',
                    $count === 1 ? '1 unchanged block ({types})' : '{count} unchanged blocks ({types})',
                    ['count' => $count, 'types' => implode(', ', $types)]
                );
            } else {
                $text = DiffGenerator::t(
                    $count === 1 ? 'diff.rows.unchanged.one' : 'diff.rows.unchanged',
                    $count === 1 ? '1 unchanged entry' : '{count} unchanged entries',
                    ['count' => $count]
                );
            }
            $html .= '<div class="k-cw-diff-collapsed">' . DiffGenerator::escape($text) . '</div>';
            $run = [];
        };

        foreach ($items as $item) {
            if ($item['status'] === 'unchanged' && !$item['moved']) {
                $run[] = $item;
                continue;
            }

            $flushRun();
            $changed++;
            $html .= self::renderItem($item, $kind);
        }
        $flushRun();

        $total = count($items);
        $badge = $kind === 'blocks'
            ? DiffGenerator::t('diff.blocks.changed', '{changed} of {total} blocks changed', ['changed' => $changed, 'total' => $total])
            : DiffGenerator::t('diff.rows.changed', '{changed} of {total} entries changed', ['changed' => $changed, 'total' => $total]);

        return [
            'html'    => '<div class="k-cw-diff-items">' . $html . '</div>',
            'badge'   => $badge,
            'changed' => $changed,
        ];
    }

    protected static function renderItem(array $item, string $kind): string
    {
        $block  = $item['new'] ?? $item['old'];
        $type   = $block['type'];
        $fields = $type !== null ? self::blockFields($type) : ['labels' => [], 'types' => []];

        if ($item['status'] === 'removed') {
            $position = DiffGenerator::t($kind === 'blocks' ? 'diff.block.was' : 'diff.row.was', $kind === 'blocks' ? 'was block {n}' : 'was entry {n}', ['n' => $item['oldIndex'] + 1]);
        } else {
            $position = DiffGenerator::t($kind === 'blocks' ? 'diff.block' : 'diff.row', $kind === 'blocks' ? 'Block {n}' : 'Entry {n}', ['n' => $item['newIndex'] + 1]);
        }

        $badges = '';
        if ($item['status'] !== 'unchanged') {
            $badges .= DiffGenerator::badge(DiffGenerator::t('diff.' . $item['status'], $item['status']), $item['status']);
        }
        if ($item['moved']) {
            $badges .= DiffGenerator::badge(DiffGenerator::t('diff.moved', 'moved'), 'moved');
        }

        $header = '<div class="k-cw-diff-item-header">'
            . ($type !== null ? '<span class="k-cw-diff-item-type">' . DiffGenerator::escape(self::blockName($type)) . '</span>' : '')
            . '<span class="k-cw-diff-item-position">' . DiffGenerator::escape($position) . '</span>'
            . '<span class="k-cw-diff-item-badges">' . $badges . '</span>'
            . '</div>';

        $oldContent = $item['old']['content'] ?? [];
        $newContent = $item['new']['content'] ?? [];
        $props      = '';

        foreach (array_unique(array_merge(array_keys($oldContent), array_keys($newContent))) as $prop) {
            $propType = $fields['types'][$prop] ?? null;
            $old      = self::valueToText($oldContent[$prop] ?? null, $propType);
            $new      = self::valueToText($newContent[$prop] ?? null, $propType);

            if ($old === $new) {
                continue;
            }

            $label  = $fields['labels'][$prop] ?? Str::label((string)$prop);
            $lines  = self::isSecret((string)$prop, $propType)
                ? self::hiddenChange()
                : DiffGenerator::renderValueChange($old, $new);
            $props .= '<div class="k-cw-diff-prop">'
                . '<span class="k-cw-diff-prop-label">' . DiffGenerator::escape($label) . '</span>'
                . '<ul class="k-cw-diff-lines">' . $lines . '</ul>'
                . '</div>';
        }

        // a changed item always tells what changed
        if ($props === '' && $item['status'] === 'changed') {
            $props = '<ul class="k-cw-diff-lines"><li class="context">'
                . DiffGenerator::escape(DiffGenerator::t('diff.formatting', 'Only the stored format changed'))
                . '</li></ul>';
        }

        return '<div class="k-cw-diff-item k-cw-diff-item-' . $item['status'] . '">'
            . $header
            . ($props !== '' ? '<div class="k-cw-diff-item-body">' . $props . '</div>' : '')
            . '</div>';
    }

    /**
     * Readable text of a block or entry property
     */
    public static function valueToText(mixed $value, ?string $type = null): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) && array_is_list($value) && array_filter($value, fn ($v) => !is_scalar($v)) === []) {
            if ($type === 'files' || self::looksLikeFiles($value)) {
                return implode(', ', array_map(fn ($v) => self::fileName((string)$v), $value));
            }

            return implode(', ', array_map(fn ($v) => self::referenceName((string)$v), $value));
        }

        if (is_array($value)) {
            return DiffGenerator::jsonEncodeFormatted($value);
        }

        $text = (string)$value;

        if ($type === 'files' || ($type === null && str_starts_with($text, 'file://'))) {
            return implode(', ', array_map(fn ($v) => self::fileName(trim($v)), preg_split('/\R|,/', $text)));
        }

        // one or more references, e.g. `page://abc, page://def`
        $ref = '(?:file|page|user):\/\/[^\s,]+';
        if (preg_match('/\A' . $ref . '(?:\s*[,\n]\s*' . $ref . ')*\z/', $text) === 1) {
            return implode(', ', array_map(fn ($v) => self::referenceName(trim($v)), preg_split('/\s*[,\n]\s*/', $text)));
        }

        // writer and list fields: keep paragraphs, drop the markup
        if ($text !== strip_tags($text)) {
            $text = preg_replace('/<br\s*\/?>|<\/(p|li|h[1-6]|blockquote)>/i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace("/\n{2,}/", "\n", $text));
        }

        return $text;
    }

    /**
     * Files, pages or users fields: names instead of the stored ids
     */
    public static function referencesToText(string $value, string $type): ?string
    {
        if (trim($value) === '') {
            return '';
        }

        try {
            $items = Data::decode($value, 'yaml');
        } catch (Throwable) {
            return null;
        }

        if (!is_array($items) || !array_is_list($items)) {
            return null;
        }

        $names = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                return null;
            }
            $names[] = $type === 'files' ? self::fileName($item) : self::modelName($item);
        }

        return implode("\n", $names);
    }

    protected static function modelName(string $value): string
    {
        try {
            $model = str_contains($value, '://') ? Uuid::for($value)?->model() : (kirby()->page($value) ?? kirby()->user($value));
            if ($model instanceof \Kirby\Cms\User) {
                return $model->name()->or($model->email())->value();
            }
            if ($model !== null && method_exists($model, 'title')) {
                return $model->title()->value();
            }
        } catch (Throwable) {
            // model deleted since
        }

        if (preg_match('/\A(page|user|site):\/\//', $value, $match) === 1) {
            return self::missing($match[1], $value);
        }

        return $value;
    }

    /** Markers around a reference that no longer resolves, see missing() */
    public const MISSING_START = "\u{E000}";
    public const MISSING_ID    = "\u{E001}";
    public const MISSING_END   = "\u{E002}";

    /**
     * "deleted page" plus the id. The markers keep the reference one word in
     * the word diff; DiffGenerator::compare() turns them into markup that
     * shows the id muted.
     */
    protected static function missing(string $kind, string $id): string
    {
        $label = DiffGenerator::t('diff.deleted.' . $kind, 'deleted ' . $kind);

        return self::MISSING_START . $label . self::MISSING_ID . $id . self::MISSING_END;
    }

    /**
     * Replace the markers of missing references in rendered (escaped) HTML
     */
    public static function missingToHtml(string $html): string
    {
        return strtr($html, [
            self::MISSING_START => '<span class="k-cw-diff-ref-missing">',
            self::MISSING_ID    => ' <span class="k-cw-diff-ref-id">',
            self::MISSING_END   => '</span></span>',
        ]);
    }

    protected static function referenceName(string $value): string
    {
        return match (true) {
            str_starts_with($value, 'file://')                                     => self::fileName($value),
            str_starts_with($value, 'page://'), str_starts_with($value, 'user://') => self::modelName($value),
            default                                                                => $value,
        };
    }

    protected static function looksLikeFiles(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value) || (!str_starts_with($value, 'file://') && preg_match('/\A[^\/\s]+\.[a-z0-9]{2,5}\z/i', $value) !== 1)) {
                return false;
            }
        }

        return $values !== [];
    }

    protected static function fileName(string $value): string
    {
        if (str_starts_with($value, 'file://')) {
            try {
                $file = Uuid::for($value)?->model();
                if ($file !== null) {
                    return $file->filename();
                }
            } catch (Throwable) {
                // file deleted since
            }

            return self::missing('file', $value);
        }

        return basename($value);
    }

    /**
     * Name of a block type from its blueprint, e.g. "Heading"
     */
    public static function blockName(string $type): string
    {
        $blueprint = self::blockBlueprint($type);
        $name      = $blueprint['name'] ?? null;

        if ($name !== null) {
            $name = I18n::translate($name, is_string($name) ? $name : null, FieldLabels::locale());
        }

        return is_string($name) && $name !== '' ? $name : Str::label($type);
    }

    /**
     * @return array{labels: array<string, string>, types: array<string, string>}
     */
    protected static function blockFields(string $type): array
    {
        $fields = self::blockBlueprint($type)['fields'] ?? [];
        $fields = is_array($fields) ? $fields : [];

        return [
            'labels' => FieldLabels::fromFields($fields, FieldLabels::locale()),
            'types'  => FieldLabels::typesFromFields($fields),
        ];
    }

    protected static function blockBlueprint(string $type): array
    {
        if (!array_key_exists($type, self::$blockBlueprints)) {
            try {
                $blueprint = Blueprint::find('blocks/' . $type);
            } catch (Throwable) {
                $blueprint = [];
            }
            self::$blockBlueprints[$type] = is_array($blueprint) ? $blueprint : [];
        }

        return self::$blockBlueprints[$type];
    }
}
