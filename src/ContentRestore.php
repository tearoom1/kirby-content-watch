<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Txt;
use Kirby\Exception\Exception as KirbyException;
use Throwable;

/**
 * Restores a page or the site to a history snapshot through Kirby's own
 * actions (`changeTemplate`, `changeSlug`, `changeStatus`, `update`), run as
 * the current user: permissions, validation, hooks and caches all apply.
 *
 * Everything that can be checked upfront is checked before anything changes.
 * If a Kirby action still fails, the steps done so far are undone.
 */
class ContentRestore
{
    use ResolvesContentModels;

    /** Content keys that are not restored as fields */
    protected const IGNORED_KEYS = ['uuid', 'slug'];

    protected ?string $error = null;

    /**
     * Restore content from a history snapshot.
     *
     * @param string $dirPath  Directory path where .content-watch.json lives
     * @param string $fileKey  File key in the history (template name or filename)
     * @param int|null    $timestamp Unix timestamp of the history entry to restore (legacy entries without id)
     */
    public function restoreContent(
        string $dirPath,
        string $fileKey,
        ?string $entryId = null,
        ?int $timestamp = null
    ): bool {
        try {
            $this->restore($dirPath, $fileKey, $entryId, $timestamp);
            $this->error = null;

            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return false;
        }
    }

    /**
     * Message of the last failed restoreContent() call
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @throws RestoreException with a message for the Panel when the restore is not possible
     */
    public function restore(
        string $dirPath,
        string $fileKey,
        ?string $entryId = null,
        ?int $timestamp = null
    ): ModelWithContent {
        if (option('tearoom1.kirby-content-watch.enableRestore') !== true) {
            throw new RestoreException('Restore functionality is disabled', 403);
        }

        $dirPath = $this->resolveContentDirectory($dirPath);
        if ($dirPath === null || $this->isSafeFileKey($fileKey) === false) {
            throw new RestoreException('History file not found', 404);
        }

        $entries = HistoryStore::read($dirPath)[$fileKey] ?? null;
        $entry   = is_array($entries) ? $this->findHistoryEntry($entries, $entryId, $timestamp) : null;

        if ($entry === null || empty($entry['content'])) {
            throw new RestoreException('This version has no content to restore', 404);
        }

        $model = $this->findContentModelByRoot($dirPath);
        if (!$model instanceof Page && !$model instanceof Site) {
            throw new RestoreException('Only pages and the site can be restored', 400);
        }

        $language = $this->resolveLanguage((string)($entry['language'] ?? ''));
        $snapshot = SnapshotSerializer::split($entry['content'], $entry['meta'] ?? null);
        $data     = $this->contentData($model, $snapshot['content'], $language);
        $plan     = $this->plan($model, $snapshot['meta'], $data, $language);

        $this->checkPermissions($model, $plan);
        $this->checkUnsavedChanges($model, $language);

        $restored = ChangeTracker::withoutTracking(fn () => $this->apply($model, $plan, $data, $language));

        // one new entry for the whole restore, the history itself stays as it is
        (new ChangeTracker())->trackContentChange($restored, [
            'action'                => 'restored',
            'restored_from'         => $entry['time'] ?? null,
            'restored_from_id'      => $entry['uuid'] ?? null,
            'restored_from_version' => $entry['version'] ?? null,
            // the history moves along with a changed template
            'previous_file_key'     => $fileKey,
        ]);

        return $restored;
    }

    /**
     * Whether the current user may restore versions of the model at all
     */
    public static function canRestore(?ModelWithContent $model): bool
    {
        return option('tearoom1.kirby-content-watch.enableRestore') === true
            && ContentWatchController::canAccess()
            && ($model instanceof Page || $model instanceof Site)
            && $model->permissions()->can('update') === true;
    }

    /**
     * Structure changes the snapshot needs. Only snapshots of the default
     * language change template, slug and status; a translation restores
     * its content and its translated slug.
     */
    protected function plan(ModelWithContent $model, array $meta, array $data, ?string $language): array
    {
        $plan = ['template' => null, 'slug' => null, 'status' => null, 'translatedSlug' => null];

        if (!$model instanceof Page) {
            return $plan;
        }

        $default   = kirby()->defaultLanguage()?->code();
        $isDefault = $language === null || $default === $language;

        if ($isDefault === false) {
            $slug = $this->translatedSlug;
            if (is_string($slug) && $slug !== '' && $slug !== $model->slug($language)) {
                $plan['translatedSlug'] = $slug;
            }

            return $plan;
        }

        $template = $meta['template'] ?? null;
        if (is_string($template) && $template !== '' && $template !== $model->intendedTemplate()->name()) {
            $plan['template'] = $template;
        }

        $slug = $meta['slug'] ?? null;
        if (is_string($slug) && $slug !== '' && $slug !== $model->slug($default)) {
            $plan['slug'] = $slug;
        }

        $status = $meta['status'] ?? null;
        if (in_array($status, ['draft', 'listed', 'unlisted'], true) && $status !== $model->status()) {
            $plan['status'] = $status;
        }

        return $plan;
    }

    protected function checkPermissions(ModelWithContent $model, array $plan): void
    {
        $permissions = $model->permissions();

        if ($permissions->can('update') !== true) {
            throw new RestoreException('You are not allowed to update this page', 403);
        }

        if (!$model instanceof Page) {
            return;
        }

        if ($plan['template'] !== null) {
            if ($permissions->can('changeTemplate') !== true) {
                throw new RestoreException('This version has a different template, and you are not allowed to change the template', 403);
            }

            $allowed = array_column($model->blueprints(), 'name');
            if (!in_array($plan['template'], $allowed, true)) {
                throw new RestoreException('This version has the template "' . $plan['template'] . '", which this page cannot switch to', 400);
            }
        }

        if (($plan['slug'] !== null || $plan['translatedSlug'] !== null) && $permissions->can('changeSlug') !== true) {
            throw new RestoreException('This version has a different URL appendix, and you are not allowed to change it', 403);
        }

        if ($plan['slug'] !== null) {
            $other = $model->parentModel()->childrenAndDrafts()->find($plan['slug']);

            if ($other !== null && $other->id() !== $model->id()) {
                throw new RestoreException('Another page already uses the URL appendix "' . $plan['slug'] . '"', 409);
            }
        }

        if ($plan['status'] !== null && $permissions->can('changeStatus') !== true) {
            throw new RestoreException('This version has a different status, and you are not allowed to change the status', 403);
        }
    }

    /**
     * Kirby 5 keeps unsaved Panel changes in a separate version. A restore
     * below them would be overwritten by the next "Save", so the restore
     * waits until the changes are saved or discarded.
     */
    protected function checkUnsavedChanges(ModelWithContent $model, ?string $language): void
    {
        $code = $language ?? 'default';

        if (method_exists($model, 'version')) {
            try {
                $hasChanges = $model->version('changes')->exists($code) === true;
            } catch (Throwable) {
                $hasChanges = false;
            }

            if ($hasChanges) {
                throw new RestoreException('This page has unsaved changes. Save or discard them first, then restore.', 409);
            }

            return;
        }

        // Kirby 4: someone else is editing the page right now
        try {
            $locked = $model->lock()?->isLocked() === true;
        } catch (Throwable) {
            $locked = false;
        }

        if ($locked) {
            throw new RestoreException('This page is being edited by someone else. Try again when they are done.', 409);
        }
    }

    protected function apply(ModelWithContent $model, array $plan, array $data, ?string $language): ModelWithContent
    {
        $undo    = [];
        $default = kirby()->defaultLanguage()?->code();

        try {
            if ($model instanceof Page) {
                if ($plan['template'] !== null) {
                    $previous = $model->intendedTemplate()->name();
                    $model    = $model->changeTemplate($plan['template']);
                    $undo[]   = fn (Page $page) => $page->changeTemplate($previous);
                }

                if ($plan['slug'] !== null) {
                    $previous = $model->slug($default);
                    $model    = $model->changeSlug($plan['slug'], $default);
                    $undo[]   = fn (Page $page) => $page->changeSlug($previous, $default);
                }

                if ($plan['translatedSlug'] !== null) {
                    $previous = $model->slug($language);
                    $model    = $model->changeSlug($plan['translatedSlug'], $language);
                    $undo[]   = fn (Page $page) => $page->changeSlug($previous, $language);
                }

                if ($plan['status'] !== null) {
                    $previous = $model->status();
                    $num      = $model->num();
                    $model    = $model->changeStatus($plan['status']);
                    $undo[]   = fn (Page $page) => $page->changeStatus($previous, $previous === 'listed' ? $num : null);
                }
            }

            return $model->update($data, $language, true);
        } catch (Throwable $e) {
            // undo the structure changes done so far, newest first
            foreach (array_reverse($undo) as $step) {
                try {
                    $model = $step($model);
                } catch (Throwable $undoError) {
                    HistoryStore::log('Could not undo a restore step: ' . $undoError->getMessage());
                }
            }

            $message = $e instanceof KirbyException || $e instanceof RestoreException
                ? $e->getMessage()
                : 'The version could not be restored: ' . $e->getMessage();

            throw new RestoreException($message, 400, $e);
        }
    }

    /** Slug field of a translated snapshot, see contentData() */
    protected ?string $translatedSlug = null;

    /**
     * Field values of the snapshot. Fields that exist now but not in the
     * snapshot are emptied, so the content matches the restored version.
     */
    protected function contentData(ModelWithContent $model, string $content, ?string $language): array
    {
        $data = Txt::decode($content);

        $this->translatedSlug = is_string($data['slug'] ?? null) ? $data['slug'] : null;

        $current = $model->content($language)->toArray();
        foreach (array_keys($current) as $key) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = null;
            }
        }

        foreach (self::IGNORED_KEYS as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    protected function resolveLanguage(string $code): ?string
    {
        if ($code === '' || kirby()->multilang() !== true) {
            return null;
        }

        if (kirby()->language($code) === null) {
            throw new RestoreException('The language "' . $code . '" of this version does not exist anymore', 400);
        }

        return $code;
    }

    protected function findHistoryEntry(array $history, ?string $entryId, ?int $timestamp): ?array
    {
        if (is_string($entryId) && $entryId !== '') {
            foreach ($history as $entry) {
                if (is_array($entry) && ($entry['uuid'] ?? null) === $entryId) {
                    return $entry;
                }
            }
        }

        if ($timestamp === null) {
            return null;
        }

        foreach ($history as $entry) {
            if (is_array($entry) && isset($entry['time']) && $entry['time'] == $timestamp) {
                return $entry;
            }
        }

        return null;
    }

    protected function resolveContentDirectory(string $dirPath): ?string
    {
        $realDir = realpath($dirPath);
        $contentRoot = realpath(kirby()->root('content'));

        if ($realDir === false || $contentRoot === false) {
            return null;
        }

        $contentRoot = rtrim($contentRoot, DIRECTORY_SEPARATOR);
        if ($realDir !== $contentRoot && !str_starts_with($realDir, $contentRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realDir;
    }

    protected function isSafeFileKey(string $fileKey): bool
    {
        return preg_match('/\A[A-Za-z0-9._-]+\z/', $fileKey) === 1
            && str_contains($fileKey, '..') === false;
    }
}
