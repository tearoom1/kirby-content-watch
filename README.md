# Content Watch Plugin for Kirby

This plugin tracks all content changes in your Kirby site and adds a panel view that displays those changes.
It shows modification date and editor information and the history of changes for each file.
This allows you to keep track of what has been changed and who made the changes.

Additionally it provides a view to see which pages are currently locked and by whom.

## Features

- **Change Tracking**: Automatically tracks all content changes in pages and files
- **Editor Attribution**: Records which editor made each change with timestamp
- **History Timeline**: Lists all content files across your Kirby site, sorted by modification date
- **File Tracking**: Tracks both page content and media file changes
- **Locked Pages View**: Shows which pages are currently being edited and by whom
- **Search Functionality**: Quickly find specific content files
- **Direct Panel Links**: One-click access to edit content in the Panel
- **Customizable Retention**: Configure how long history is kept

## Installation

### Manual

1. Download or clone this repository
2. Place the folder `kirby-content-watch` in `/site/plugins/`

### Composer

```bash
composer require tearoom1/kirby-content-watch
```

## Usage

After installation, you'll see a new "Content Watch" item in the Panel menu. Click on it to access:

- **Files View**: All content files with their modification history
- **Locked Pages**: Overview of currently locked pages and who is editing them

## Configuration

You can configure the plugin in your `config.php`:

```php
return [
    'tearoom1.content-watch' => [
        // How many days to keep history entries (default: 30)
        'retentionDays' => 30,
        
        // Maximum number of history entries to keep per file (default: 10)
        'retentionCount' => 10,
        
        // Whether to show locked pages in the interface (default: true)
        'enableLockedPages' => true
    ]
];
```

## How It Works

The plugin creates a `.content-watch.json` file in each content directory that has been modified. This file stores the history of changes including editor information and timestamps.

History entries are automatically pruned based on your retention settings.

## Requirements

- Kirby 4.x or 5.x
- PHP 8.0+

## License

MIT

## Credits

- Developed by TearoomOne
