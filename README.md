# Content History Plugin for Kirby

This plugin adds a panel view that displays all content files in your Kirby site, sorted by modification date and showing the editor information.

## Features

- Lists all content files (*.txt) across your Kirby site
- Sorts files by last modification date (newest first)
- Shows editor information for each file
- Pagination support
- Direct links to edit content in the Panel

## Installation

### Manual

1. Download or clone this repository
2. Place the folder `content-watch` in `/site/plugins/`

### Composer

```bash
composer require my/content-watch
```

## Usage

After installation, you'll see a new "Content History" item in the Panel menu. Click on it to view the list of content files sorted by modification date.

## Configuration

You can configure the plugin in your `config.php`:

```php
return [
    'my.content-watch.pagination' => 20, // Number of items per page
];
```
