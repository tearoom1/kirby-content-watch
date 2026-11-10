<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\File;
use Kirby\Cms\Site;
use Kirby\Form\Field\DisplayField;
use Kirby\Reflection\Attributes\Prop;
use Kirby\Toolkit\Str;

/**
 * The `contentwatch` field: recent changes of the current
 * page, file or site, with diff and restore.
 * Until Kirby 5 this was the `contentwatch` section.
 */
class ContentWatchField extends DisplayField
{
    /**
     * The headline of the section in Kirby 5, kept as alias of `label`
     */
    #[Prop(export: false)]
    protected array|string|null $headline = null;

    /**
     * The number of changes that are shown
     */
    #[Prop]
    protected int $limit = 5;

    /**
     * The history is not part of the form values, so the
     * field refreshes it through its own endpoint after saving
     */
    public function api(): array
    {
        $field = $this;

        return [
            [
                'pattern' => '',
                'method'  => 'GET',
                'action'  => fn (): array => $field->state()
            ]
        ];
    }

    /**
     * Link to the Content Watch area, filtered to the model
     */
    public function areaUrl(): string
    {
        $model  = $this->model();
        $search = match (true) {
            $model instanceof File => $model->filename(),
            $model instanceof Site => 'Site',
            default                => $model->title()->value(),
        };

        return 'content-watch?' . http_build_query([
            'search' => $search,
            'all'    => $model instanceof File ? 1 : null,
        ]);
    }

    public function canAccess(): bool
    {
        return ContentWatchController::canAccess();
    }

    /**
     * The newest changes, each with the version before it
     */
    public function entries(): array
    {
        if ($this->canAccess() !== true) {
            return [];
        }

        $entries = (new ContentWatchController())->getModelHistory($this->model());

        // the diff of the last shown entry needs the one before it
        foreach ($entries as $index => $entry) {
            $previous = $entries[$index + 1] ?? null;

            $entries[$index]['previous'] = $previous ? [
                'entry_id'     => $previous['entry_id'],
                'time'         => $previous['time'],
                'version'      => $previous['version'],
                'has_snapshot' => $previous['has_snapshot'],
            ] : null;
        }

        return array_slice($entries, 0, max(1, $this->limit));
    }

    /**
     * Where the history is stored and what the user may do with it
     */
    public function history(): array|null
    {
        if ($this->canAccess() !== true) {
            return null;
        }

        [$dirPath, $fileKey] = (new ContentWatchController())->modelHistoryLocation($this->model());

        // same rules as in the Content Watch area: diffs are part of restoring
        $enableRestore = option('tearoom1.kirby-content-watch.enableRestore') === true;

        return [
            'dirPath'       => $dirPath,
            'fileKey'       => $fileKey,
            'enableDiff'    => $enableRestore && option('tearoom1.kirby-content-watch.enableDiff', true) === true,
            'enableRestore' => $enableRestore,
        ];
    }

    /**
     * `label` wins, then the former `headline`, then the default.
     * Blueprints fill in the field name as label, that counts as no label.
     */
    public function label(): string
    {
        $label = $this->label;

        if ($label === null || $label === Str::label($this->name())) {
            $label = $this->headline ?? 'Recent changes';
        }

        return $this->stringTemplateI18n($label);
    }

    public function props(): array
    {
        return [
            ...parent::props(),
            ...$this->state()
        ];
    }

    /**
     * Everything the Vue component shows; also the answer of the field endpoint
     */
    public function state(): array
    {
        return [
            'areaUrl'   => $this->areaUrl(),
            'canAccess' => $this->canAccess(),
            'entries'   => $this->entries(),
            'headline'  => $this->label(),
            'history'   => $this->history(),
        ];
    }
}
