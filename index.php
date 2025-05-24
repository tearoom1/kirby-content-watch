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
    $dirPath = $content->root();
    $fileKey = $content->slug();

    if (!$fileKey) return;

    // Load existing history or create empty array
    $history = [];

    $editorFile = $dirPath . '/.content-history.json';
    if (file_exists($editorFile)) {
        try {
            $history = json_decode(file_get_contents($editorFile), true) ?: [];
            
            // Convert old format (direct key => value) to new format (key => [array of entries])
            foreach ($history as $key => $value) {
                if (is_array($value)) {
                    if (!isset($value[0]) || !is_array($value[0])) {
                        // Convert to new format
                        $history[$key] = [$value];
                    }
                } else {
                    // Handle non-array values
                    $history[$key] = [];
                }
            }
        } catch (\Exception $e) {
            // Ignore errors, start with empty history
        }
    }

    // Initialize file history if it doesn't exist
    if (!isset($history[$fileKey]) || !is_array($history[$fileKey])) {
        $history[$fileKey] = [];
    }

    // Get history retention period from options (default 30 days)
    $retentionDays = (int)option('tearoom1.content-history.retentionDays', 30);
    $cutoffTime = time() - ($retentionDays * 86400); // 86400 seconds per day

    // Add new history entry to the beginning of the array
    array_unshift($history[$fileKey], $editorData);

    // Filter out entries older than the retention period
    $history[$fileKey] = array_filter($history[$fileKey], function($entry) use ($cutoffTime) {
        return isset($entry['time']) && $entry['time'] >= $cutoffTime;
    });

    // Save the updated history
    try {
        file_put_contents($editorFile, json_encode($history));
    } catch (\Exception $e) {
        // Silently fail if we can't write the file
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
        'retentionDays' => 30, // default to 30 days of history
        'enableLockedPages' => true,
    ],
]);
