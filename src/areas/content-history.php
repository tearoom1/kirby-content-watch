<?php

namespace ContentHistory;

use ContentHistory\LockedPages;

return [
    'label' => 'Content History',
    'icon' => 'text-justify',
    'menu' => true,
    'link' => 'content-history',
    'views' => [
        [
            'pattern' => 'content-history',
            'action' => function () {
                $contentDir = kirby()->root('content');
                $files = [];
                $allHistoryEntries = [];

                // Recursively find all content files
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($contentDir)
                );

                // First collect all history files
                $historyFiles = [];
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getBasename() === '.content-history.json') {
                        try {
                            $history = json_decode(file_get_contents($file->getPathname()), true) ?: [];
                            $dirPath = dirname($file->getPathname());
                            $historyFiles[$dirPath] = $history;
                        } catch (\Exception $e) {
                            // Skip invalid history files
                        }
                    }
                }

                // Process all content files
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'txt') {
                        $filePath = $file->getPathname();
                        $relativePath = str_replace($contentDir . '/', '', $filePath);
                        $modified = $file->getMTime();
                        
                        // Get directory and basename
                        $dirPath = dirname($filePath);
                        $fileId = preg_replace('%_?drafts/%', '', dirname($relativePath));
                        $fileId = preg_replace('%\\d+_%','', $fileId);
                        $fileName = basename($fileId);
                        $pathId = preg_replace('%/%', '+', $fileId);

                        $page = kirby()->page($fileId);
                        $title = 'Unknown';
                        if ($page) {
                            $title = $page->title()->value();
                        }

                        // Get editor history for this file
                        $historyEntries = [];
                        if (isset($historyFiles[$dirPath]) && isset($historyFiles[$dirPath][$fileName])) {
                            $historyEntries = $historyFiles[$dirPath][$fileName];
                        }

                        // Use latest history entry for file display
                        $editor = [
                            'id' => 'unknown',
                            'name' => 'Unknown',
                            'email' => '',
                            'time' => $modified
                        ];
                        
                        if (!empty($historyEntries) && is_array($historyEntries) && isset($historyEntries[0])) {
                            // The latest entry is at index 0 because we used array_unshift when adding
                            $editor = $historyEntries[0];
                        }

                        // Try to determine panel URL
                        $pathParts = explode('/', $relativePath);

                        if ($fileId === '.') {
                            $fileId = 'site';
                            $panelUrl = '/site';
                            $title = 'Site';
                        } else {
                            $panelUrl = '/pages/' . $pathId;
                        }

                        // Build file data
                        $fileData = [
                            'id' => $fileId,
                            'path' => dirname($relativePath),
                            'title' => $title,
                            'parent' => end($pathParts) ?: 'root',
                            'modified' => $editor['time'] ?? $modified,
                            'modified_formatted' => date('Y-m-d H:i:s', $editor['time'] ?? $modified),
                            'editor' => [
                                'id' => $editor['id'],
                                'name' => $editor['name'],
                                'email' => $editor['email']
                            ],
                            'panel_url' => $panelUrl,
                            'history' => []
                        ];
                        
                        // Add history entries
                        foreach ($historyEntries as $entry) {
                            if (!is_array($entry)) {
                                continue; // Skip non-array entries
                            }
                            
                            $fileData['history'][] = [
                                'editor' => [
                                    'id' => $entry['id'] ?? 'unknown',
                                    'name' => $entry['name'] ?? 'Unknown',
                                    'email' => $entry['email'] ?? ''
                                ],
                                'time' => $entry['time'] ?? 0,
                                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0)
                            ];
                        }
                        
                        $files[] = $fileData;
                        
                        // Add to global history entries list for timeline view
                        foreach ($historyEntries as $entry) {
                            if (!is_array($entry)) {
                                continue; // Skip non-array entries
                            }
                            
                            $allHistoryEntries[] = [
                                'file_id' => $fileId,
                                'file_path' => dirname($relativePath),
                                'file_title' => $title,
                                'panel_url' => $panelUrl,
                                'editor' => [
                                    'id' => $entry['id'] ?? 'unknown',
                                    'name' => $entry['name'] ?? 'Unknown',
                                    'email' => $entry['email'] ?? ''
                                ],
                                'time' => $entry['time'] ?? 0,
                                'time_formatted' => date('Y-m-d H:i:s', $entry['time'] ?? 0)
                            ];
                        }
                    }
                }

                // Sort by modification date (newest first)
                usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
                
                // Sort all history entries by time (newest first)
                usort($allHistoryEntries, fn($a, $b) => $b['time'] <=> $a['time']);
                
                // Get retention days setting
                $retentionDays = (int)option('tearoom1.content-history.retentionDays', 30);

                $lockedPages = new LockedPages();
                return [
                    'component' => 'content-history',
                    'title' => 'Content History',
                    'props' => [
                        'lockedPages' => (bool)option('tearoom1.content-history.enableLockedPages', true) ? $lockedPages->getLockedPages() : [],
                        'files' => $files,
                        'historyEntries' => $allHistoryEntries,
                        'retentionDays' => $retentionDays
                    ],
                ];
            }
        ],
    ],
];
