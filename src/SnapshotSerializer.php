<?php

namespace TearoomOne\ContentWatch;

class SnapshotSerializer
{
    protected const META_FIELDS = ['path' => 'Path', 'slug' => 'Slug', 'status' => 'Status', 'template' => 'Template'];

    public static function split(mixed $content, mixed $meta = null): array
    {
        $snapshot = [
            'content' => is_string($content) ? $content : '',
            'meta'    => is_array($meta) ? $meta : [],
        ];

        if ($snapshot['meta'] !== []) {
            return $snapshot;
        }

        $remaining = $snapshot['content'];
        foreach (self::META_FIELDS as $key => $label) {
            if (preg_match('/\A' . $label . ': ([^\n]*)\n----\n/', $remaining, $matches) !== 1) {
                continue;
            }

            $snapshot['meta'][$key] = $matches[1];
            $remaining = substr($remaining, strlen($matches[0]));
        }

        $snapshot['content'] = $remaining;

        return $snapshot;
    }

    public static function compose(mixed $content, mixed $meta = null): string
    {
        $snapshot = self::split($content, $meta);
        $prefix   = '';

        foreach (self::META_FIELDS as $key => $label) {
            $value = $snapshot['meta'][$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            $prefix .= $label . ': ' . $value . "\n----\n";
        }

        return $prefix . $snapshot['content'];
    }
}
