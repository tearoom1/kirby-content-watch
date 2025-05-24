<?php

// support manual installation in plugins folder
use Kirby\Cms\ModelWithContent;

@include_once __DIR__ . '/vendor/autoload.php';

// don't load plugin if it's disabled in the config.
if (option('tearoom1.content-history.disable', false)) {
    return;
}

// Helper function to track content changes
function trackContentChange(ModelWithContent $content)
{
    $user = kirby()->user();
    if (!$user) return;

    $editorData = [
        'id' => $user->id(),
        'name' => (string)$user->name(),
        'email' => (string)$user->email(),
        'time' => time()
    ];

    // Determine the history file path and filename key
    $contentPath = $content->root();
    $fileName = $content->slug();
    $dirPath = kirby()->root('content') . '/' . is_dir($contentPath) ? $contentPath : dirname($contentPath);
    $fileKey = $fileName ?? (is_file($contentPath) ? basename($contentPath) : null);

    if (!$fileKey) return;

    // Load existing history or create empty array
    $history = [];

    $editorFile = $dirPath . '/.content-history.json';
    if (file_exists($editorFile)) {
        try {
            $history = json_decode(file_get_contents($editorFile), true) ?: [];
        } catch (\Exception $e) {
            // Ignore errors, start with empty history
        }
    }

    // Add new entry with the file key
    $history[$fileKey] = $editorData;

    // Save the updated history
    try {
        file_put_contents($editorFile, json_encode($history));
    } catch (\Exception $e) {
        // Silently fail if we can't write the file
        echo $e->getMessage();
    }
}

Kirby::plugin('tearoom1/content-history', [
    'hooks' => [
        'page.create:after' => function ($page) {
            trackContentChange($page);
        },
        'page.update:after' => function ($newPage, $oldPage) {
            trackContentChange($newPage);
        },
        'site.update:after' => function ($newSite, $oldSite) {
            trackContentChange($newSite);
        },
        'file.create:after' => function ($file) {
            trackContentChange($file);
        },
        'file.update:after' => function ($newFile, $oldFile) {
            trackContentChange($newFile);
        }
    ],
    'areas' => [
        'content-history' => require __DIR__ . '/src/areas/content-history.php',
    ],
    'options' => [
        'pagination' => 20,
    ],
]);
