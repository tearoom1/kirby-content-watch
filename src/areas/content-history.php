<?php

namespace ContentHistory;

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

                // Recursively find all content files
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($contentDir)
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'txt') {
                        $relativePath = str_replace($contentDir . '/', '', $file->getPathname());
                        $modified = $file->getMTime();
                        $content = \Kirby\Filesystem\F::read($file->getPathname());

                        // Try to extract title from content
                        $title = basename($file->getPathname(), '.txt');

                        // Get editor info from hidden history file if it exists
                        $editor = [
                            'id' => 'unknown',
                            'name' => 'Unknown',
                            'email' => '',
                            'time' => $modified
                        ];
                        $historyFile = dirname($file->getPathname()) . '/.content-history.json';
                        $pathInfo = pathinfo($file->getPathname());
                        $basename = $pathInfo['filename'];
                        // remove the langue from the filename
                        $basename = preg_replace('/(\.[a-z]{2})/', '', $basename);
                        if (file_exists($historyFile)) {
                            try {
                                $history = json_decode(file_get_contents($historyFile), true) ?: [];
                                if (isset($history[$basename])) {
                                    $editor = $history[$basename];
                                }
                            } catch (\Exception $e) {
                                // Silently fail, use default editor info
                            }
                        }

                        // Try to determine panel URL
                        $pathParts = explode('/', $relativePath);

                        if (empty($pathParts)) {
                            $panelUrl = '/site';
                        } else {
                            $panelUrl = '/pages/' . $basename;
                        }

                        // remove the prefix number_ from relativePath for id

                        $files[] = [
                            'id' => $basename,
                            'path' => $relativePath,
                            'title' => $title,
                            'parent' => end($pathParts) ?: 'root',
                            'modified' => $editor['time'] ?? $modified, // Use editor time if available
                            'modified_formatted' => date('Y-m-d H:i:s', $editor['time'] ?? $modified),
                            'editor' => [
                                'id' => $editor['id'],
                                'name' => $editor['name'],
                                'email' => $editor['email']
                            ],
                            'panel_url' => $panelUrl
                        ];
                    }
                }

                // Sort by modification date (newest first)
                usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

                return [
                    'component' => 'content-history',
                    'title' => 'Content History',
                    'props' => [
                        'files' => $files
                    ],
                ];
            }
        ],
    ],
];
