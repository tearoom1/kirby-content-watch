<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Toolkit\I18n;
use Throwable;

/**
 * Resolves readable field labels for content keys from the current blueprint
 * of a page, file or the site, translated into the Panel language.
 */
class FieldLabels
{
    /**
     * Kirby core translation keys for fields every page has, and for the
     * page meta that is prepended to snapshots (see SnapshotSerializer)
     */
    protected const CORE_LABELS = [
        'title'    => 'title',
        'slug'     => 'slug',
        'status'   => 'status',
        'template' => 'template',
        'path'     => 'tearoom1.content-watch.field.path',
    ];

    /**
     * @return array<string, string> content key => label
     */
    public static function forModel(?ModelWithContent $model, ?string $locale = null): array
    {
        $locale ??= static::locale();
        $labels   = [];

        foreach (self::CORE_LABELS as $key => $translationKey) {
            $label = I18n::translate($translationKey, null, $locale);
            if (is_string($label) && $label !== '') {
                $labels[$key] = $label;
            }
        }

        if ($model === null) {
            return $labels;
        }

        try {
            $fields = $model->blueprint()->fields();
        } catch (Throwable) {
            return $labels;
        }

        return array_replace($labels, static::fromFields($fields, $locale));
    }

    /**
     * @param array $fields field definitions as returned by Blueprint::fields()
     * @return array<string, string>
     */
    public static function fromFields(array $fields, string $locale): array
    {
        $labels = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            // groups only bundle fields, their fields are stored on the model itself
            if (($field['type'] ?? null) === 'group' && is_array($field['fields'] ?? null)) {
                $labels = array_replace($labels, static::fromFields($field['fields'], $locale));
                continue;
            }

            $key   = static::normalizeKey((string)($field['name'] ?? $name));
            $label = $field['label'] ?? null;

            if (is_array($label) || is_string($label)) {
                $label = I18n::translate($label, is_string($label) ? $label : null, $locale);
            }

            if (is_string($label) && trim($label) !== '') {
                $labels[$key] = trim(strip_tags($label));
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string> content key => blueprint field type
     */
    public static function typesForModel(?ModelWithContent $model): array
    {
        if ($model === null) {
            return [];
        }

        try {
            return static::typesFromFields($model->blueprint()->fields());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    public static function typesFromFields(array $fields): array
    {
        $types = [];

        foreach ($fields as $name => $field) {
            if (!is_array($field)) {
                continue;
            }

            if (($field['type'] ?? null) === 'group' && is_array($field['fields'] ?? null)) {
                $types = array_replace($types, static::typesFromFields($field['fields']));
                continue;
            }

            if (is_string($field['type'] ?? null)) {
                $types[static::normalizeKey((string)($field['name'] ?? $name))] = $field['type'];
            }
        }

        return $types;
    }

    /**
     * Same normalization as Kirby\Data\Txt::decode() for content keys
     */
    public static function normalizeKey(string $key): string
    {
        return str_replace(['-', ' '], '_', strtolower(trim($key)));
    }

    /**
     * Panel language of the current user
     */
    public static function locale(): string
    {
        $kirby = kirby();

        try {
            $language = $kirby->user()?->language();
        } catch (Throwable) {
            $language = null;
        }

        return $language ?: $kirby->panelLanguage();
    }
}
