<?php

namespace TearoomOne\ContentWatch;

use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;

trait ResolvesContentModels
{
    protected static array $contentModels = [];

    protected function findContentModelByRoot(string $root): ModelWithContent|null
    {
        $root = realpath($root) ?: $root;
        $siteRoot = $this->contentModelCacheKey();

        if (isset(self::$contentModels[$siteRoot]) === false) {
            self::$contentModels[$siteRoot] = $this->buildContentModelMap();
        }

        return self::$contentModels[$siteRoot][$root] ?? null;
    }

    protected function buildContentModelMap(): array
    {
        $site     = kirby()->site();
        $siteRoot = realpath($site->root()) ?: $site->root();
        $models   = [$siteRoot => $site];

        foreach ($site->index(true) as $page) {
            $pageRoot = realpath($page->root()) ?: $page->root();
            $models[$pageRoot] = $page;
        }

        return $models;
    }

    protected function contentModelCacheKey(): string
    {
        $site = kirby()->site();

        return realpath($site->root()) ?: $site->root();
    }

    protected function pageStatus(Page $page): string
    {
        return match ($page->status()) {
            'listed' => 'listed',
            'draft' => 'draft',
            default => 'unlisted',
        };
    }
}
