<?php

namespace ContentHistory;

return [
    'label' => 'Content History',
    'icon'  => 'history',
    'menu'  => true,
    'link'  => 'content-history',
    'views' => [
        [
            'pattern' => 'content-history',
            'action'  => function () {
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
                        $title = '';
                        if (preg_match('/title:\s*(.+)$/m', $content, $matches)) {
                            $title = trim($matches[1]);
                        } else {
                            $title = basename($file->getPathname(), '.txt');
                        }
                        
                        // Get editor info
                        $editor = [
                            'id' => 'unknown',
                            'name' => 'Unknown',
                            'email' => ''
                        ];
                        
                        // Try to determine panel URL
                        $panelUrl = '';
                        $pathParts = explode('/', $relativePath);
                        $filename = array_pop($pathParts);
                        
                        if (empty($pathParts)) {
                            $panelUrl = '/panel/site';
                        } else {
                            $pageId = implode('/', $pathParts);
                            $panelUrl = '/panel/pages/' . $pageId;
                        }
                        
                        $files[] = [
                            'id' => $relativePath,
                            'path' => $relativePath,
                            'title' => $title,
                            'parent' => end($pathParts) ?: 'root',
                            'modified' => $modified,
                            'modified_formatted' => date('Y-m-d H:i:s', $modified),
                            'editor' => $editor,
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
