<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;

/**
 * Tells cheaply whether a history snapshot equals the current content:
 * one hash of the current content file per language (computed on first
 * use), compared with the hash stored in the entry. Older entries without
 * a stored hash are hashed from their snapshot.
 */
class CurrentContent
{
    /** @var array<string, string|null> language => hash of the current file */
    protected array $hashes = [];

    protected ?array $meta = null;

    public function __construct(
        protected ?ModelWithContent $model,
        protected ?string $dirPath,
        protected ?string $fileKey
    ) {
    }

    public static function hash(string $content): string
    {
        return md5(trim(str_replace("\r\n", "\n", $content)));
    }

    public function equals(array $entry): bool
    {
        if ($this->dirPath === null || $this->fileKey === null || empty($entry['content'])) {
            return false;
        }

        $language = (string)($entry['language'] ?? '');
        $current  = $this->currentHash($language);

        if ($current === null) {
            return false;
        }

        $snapshot = SnapshotSerializer::split($entry['content'], $entry['meta'] ?? null);
        $hash     = isset($entry['hash'], $entry['meta']) && is_string($entry['hash'])
            ? $entry['hash']
            : self::hash($snapshot['content']);

        if ($hash !== $current) {
            return false;
        }

        // template, slug, status and parent count too
        return $snapshot['meta'] === [] || $this->metaEquals($snapshot['meta']);
    }

    protected function currentHash(string $language): ?string
    {
        if (array_key_exists($language, $this->hashes)) {
            return $this->hashes[$language];
        }

        $file = $this->dirPath . '/' . $this->fileKey . ($language !== '' ? '.' . $language : '') . '.txt';

        return $this->hashes[$language] = is_file($file)
            ? self::hash((string)file_get_contents($file))
            : null;
    }

    protected function metaEquals(array $meta): bool
    {
        if (!$this->model instanceof Page) {
            return true;
        }

        $this->meta ??= [
            'path'     => $this->model->parent()?->id() ?? '',
            'slug'     => $this->model->slug(kirby()->defaultLanguage()?->code()),
            'status'   => $this->model->status(),
            'template' => $this->model->intendedTemplate()->name(),
        ];

        foreach ($meta as $key => $value) {
            if (array_key_exists($key, $this->meta) && (string)$value !== (string)$this->meta[$key]) {
                return false;
            }
        }

        return true;
    }
}
