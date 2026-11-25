# Content Watch Plugin for Kirby

This plugin tracks all content changes in your Kirby site and adds a panel view that displays those changes.
It shows modification date and editor information and the history of changes for each file.
This allows you to keep track of what has been changed and who made the changes.

Additionally it provides a view to see which pages are currently locked and by whom.

[![Screenshot](screenshot.jpg)](https://github.com/tearoom1/kirby-content-watch)

***

## Features

- **Change Tracking**: Automatically tracks all content changes in pages and files
- **Editor Attribution**: Records which editor made each change with timestamp
- **History Timeline**: Lists all content files across your Kirby site, sorted by modification date
- **Locked Pages View**: Shows which pages are currently being edited and by whom
- **Search & Filters**: Find content by title or path and filter by author, status, template, period and language
- **Notifications**: Call a webhook or closure on every change, e.g. for Slack or mail
- **CSV Export**: Export the currently filtered change history as a CSV file for audits
- **Direct Panel Links**: One-click access to edit content in the Panel
- **Customizable Retention**: Configure how long history is kept
- **Version Restore**: Restore previous versions of content with a single click (optional)
- **Two Views**: Browse changes by page, or as a timeline of all changes grouped by day, where a deploy's changes outside the Panel collapse into one row
- **Starting Points**: The state before the first recorded change is kept, so the first change can be compared and undone too
- **Dark Mode**: Supports the Panel's dark mode
- **History Field**: Show recent changes of a page, the site or a file directly in its blueprint, with diff and restore
- **Page Method**: Access change history programmatically via `$page->contentHistory()`

## Known Limitations

This plugin has been developed for rather smaller websites. It has not been tested on big sites or slow servers.
It stores all history entries in a single file for each content directory, and goes through them each time you open the plugins page.
This may be a problem for sites with a large number of files. With `detectExternal`, every content directory gets such a file; Content Watch then only reads the content folders that changed since the last check.

The restore feature also has some limitations
- binary files are not supported
- when restoring a page, its files are not restored
- only pages and the site can be restored; directories without a Kirby model (e.g. `_drafts`) are rejected
- a page is not moved back to a former parent page
- a page with unsaved changes in the Panel cannot be restored until the changes are saved or discarded
- a version that fails the current blueprint validation (e.g. an empty required field) cannot be restored as a published page

Restore runs through Kirby's own page actions as the current user, so the user's permissions, blueprint validation, hooks and the page cache all apply. Each restore adds a new entry to the history, so it can be undone. As with any tool that changes content, keep regular backups.

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

- **By page**: All content files with their modification history
- **Timeline**: All changes across pages, the site and files, newest first and grouped by day (Today, Yesterday, then dates). Changes outside the Panel within five minutes of each other, typically a deploy, collapse into one row such as "Outside the Panel · 12 pages"; so do starting points stored together, e.g. "Starting point · 150 pages" (see Starting Points). Search and filters apply to both views. The timeline is paginated in the browser with the chosen page size.
- **Locked Pages**: Overview of currently locked pages and who is editing them

Each file in the list can be expanded to show its modification history. For each history entry with a snapshot, a restore button is available to revert to that previous version.

Changes that were not made in the Panel, e.g. by a deploy, FTP, git or a script, have no Panel user. They are shown as **Outside the Panel**; hover the name for an explanation. Changes by users that were deleted since are shown as **Deleted user**. A starting point, the content as Content Watch first found it, is shown as **Starting point**.

## Configuration

You can configure the plugin in your `config.php`:

```php
return [
    'tearoom1.kirby-content-watch' => [
        'allowedRoles'     => [],
        'retentionDays'    => 30,
        'retentionCount'   => 10,
        'defaultPageSize'  => 20,
        'defaultView'      => 'page',
        'enableLockedPages' => null,
        'enableRestore'    => false,
        'enableDiff'       => true,
        'notify'           => null,
        'detectExternal'   => false,
        'disable'          => false,
    ]
];
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `allowedRoles` | array | `[]` | Additional Kirby roles allowed to use the plugin. Admins always have access (see Access Control) |
| `retentionDays` | `int` | `30` | Number of days to keep history entries before they are pruned. The newest older entry of each language stays as the state the first kept change started from (see Retention) |
| `retentionCount` | `int` | `10` | Maximum number of history entries to keep per file and language (at least one is always kept) |
| `defaultPageSize` | `int` | `20` | Default number of items per page in the panel view. Possible values: `10`, `20`, `50` |
| `defaultView` | `string` | `'page'` | View the area opens with: `'page'` (changes grouped by page) or `'timeline'` (all changes, newest first). Editors can switch with the toggle in the toolbar; their choice is remembered per user in the browser and wins over this option |
| `layoutStyle` | `string` | `'compact'` | **Deprecated.** The list always uses the compact layout; `'relaxed'` and `'default'` are still accepted but have no effect |
| `enableLockedPages` | `bool\|null` | `null` | Whether to show the locked pages view. `null` hides it, since Kirby has its own "Changes" overview |
| `enableRestore` | `bool` | `false` | Enable content restore functionality. When enabled, full content snapshots are saved — increases disk usage. Before the first Panel change of a page or the site without history, its previous state is stored as starting point, so that change can be undone (see Starting Points) |
| `enableDiff` | `bool` | `true` | Enable content diff view. Requires `enableRestore` to be `true` |
| `notify` | `string\|Closure\|null` | `null` | Webhook URL (JSON `POST`) or closure called after every tracked change. See Notifications |
| `detectExternal` | `bool` | `false` | Record content changed outside the Panel (deploy, rsync, FTP, git, scripts) as a history entry "Outside the Panel". Every page, file and the site gets a history file with a starting point, so the first opening of the Content Watch area takes longer and uses disk space. See Changes Outside the Panel |
| `disable` | `bool` | `false` | Completely disable the plugin without uninstalling it |

### Notifications

Get notified whenever content changes, e.g. in Slack, Matrix or by mail. Set `notify` to a webhook URL to receive a JSON `POST`, or to a closure to handle the payload yourself:

```php
'tearoom1.kirby-content-watch' => [
    // Webhook
    'notify' => 'https://hooks.example.com/content-watch',

    // or a closure, e.g. to send a mail
    'notify' => function (array $change) {
        kirby()->email([
            'from'    => 'cms@example.com',
            'to'      => 'editor-in-chief@example.com',
            'subject' => $change['title'] . ' was ' . $change['action'] . ' by ' . $change['editor']['name'],
            'body'    => $change['panel_url'],
        ]);
    },
],
```

The payload contains `event`, `type` (`page`/`file`), `action` (`edited`, `moved`, `duplicated`), `id`, `title`, `version`, `language`, `time`, `editor` (`id`, `name`, `email`) and `panel_url`. Content snapshots are never sent. Webhook requests time out after 3 seconds, and failing notifications never block saving.

### Changes Outside the Panel

Content Watch records changes through Kirby's hooks, so content files written directly (an agent or a deploy that uploads content, rsync, FTP, git, a script) leave no entry. A later Panel save that sets a field back then looks like no change at all. Turn on `detectExternal` to see these changes too:

```php
'tearoom1.kirby-content-watch' => [
    'detectExternal' => true,
],
```

Content Watch then compares the newest entry of a page, the site or a file with its content file on disk. When they differ, it adds an entry without a user, shown as "Outside the Panel". Content that has no history yet, e.g. a page never changed in the Panel, first gets a starting point (see Starting Points), so its next change, in the Panel or outside, has something to be compared with. The comparison runs

- when the Content Watch area or a history field is opened,
- before Kirby writes a Panel change (update, title, slug, status, template, move, file changes), so the outside version is kept and the diff of the Panel change only shows what was changed in the Panel,
- before a restore, so the version it replaces stays in the history.

What turning it on means:

- **One history file per page, file and site.** Every content folder gets a `.content-watch.json`, also for content never changed in the Panel. Files are included when they have a metadata file (`photo.jpg.txt`). If your deploy or git setup syncs the content folder, exclude `.content-watch.json` or let it be part of the content, as with the history of Panel changes.
- **The first opening takes longer.** The first time the Content Watch area is opened, Content Watch reads every content file once and writes the starting points; on a site with a few thousand pages this takes a moment. Later openings only read the content folders that changed since (see below).
- **Disk use.** With `enableRestore`, each starting point holds a full snapshot of its content file, so the history files together take roughly as much space as the content files, plus the snapshots of later changes. Without `enableRestore`, a starting point is a short hash.

Details:

- Each language is compared on its own and gets its own starting point; a translation that doesn't exist yet gets none.
- Nothing is checked on normal page requests, only in the Panel moments listed above.
- The comparison uses a hash of the content file (and template, slug, status and parent, when restore is on). With `enableRestore` an entry has a snapshot, so it can be compared and restored like any other. Without it the entry only shows that and when the content changed.
- To keep opening the area cheap on large sites, Content Watch remembers for each content folder the name, modification time, change time and size of its content files and its history file at the last check, in Kirby's cache folder (by default `site/cache/content-watch/`, never in the content folder). Folders where none of these changed are not read again. Clearing the cache only means that every folder is checked once more. A folder written within the last two seconds is checked again next time, since file times have a resolution of one second.
- Entries from before the option was turned on that have no hash (when `enableRestore` is off) or no language are not compared; detection for such a page starts with its next Panel change.
- Files are compared by the metadata of the default language.
- Template, slug, status and parent belong to the page, not to a language: they are compared once, in the default language. A content file renamed outside the Panel (a template change) keeps its history.
- The time of an entry is the modification time of the content file when it lies between the previous entry and now (rsync and most deploys keep it), otherwise the time the change was noticed.
- One outside change gives one entry, however often the area is opened. Several uploads between two checks show as one entry.
- No notification is sent for outside changes or starting points.
- With the option off, nothing changes: no comparison, no starting points for content without history, and entries are stored as before.

### Starting Points

A starting point (baseline) is the content as Content Watch first found it. It looks like any other entry: it can be compared with the current version or the next change and, with `enableRestore`, restored. Its author is shown as "Starting point" (hover it for an explanation), and its tag is `baseline`. It is no change: it sends no notification, and the "By page" list keeps showing the last real change (or the file time) for a page that only has a starting point. In the history file it is an entry with `source: baseline` and no user.

Starting points are stored

- with `detectExternal`: for every page, file and the site without history, when the Content Watch area or a history field is opened or before a Panel change (see Changes Outside the Panel). Starting points stored together collapse into one row in the timeline, e.g. "Starting point · 150 pages".
- with `enableRestore` (also without `detectExternal`): for a page or the site without history, right before its first Panel change, in every language that has a content file. The first change can then be undone, and its diff shows only what was changed in the Panel. Files can't be restored, so they get no starting point here.

No option of its own is needed: the first case is part of `detectExternal`, the second part of `enableRestore`.

### Retention

`retentionDays` and `retentionCount` apply per file and language, both when a change is stored and when the history is shown:

- Entries within the last `retentionDays` days stay.
- Of the older entries, the newest stays too: it is the state the first change in the window started from, so that change can still be compared and undone. When nothing changed within the window, it is the last known state, which `detectExternal` compares with. A starting point is pruned like any other entry, once a newer entry has taken over this role, so content is never left without a state to compare with and never gets a second starting point because of retention.
- Then the newest `retentionCount` entries are kept, at least one. The oldest kept entry is the base the others are compared with; it has no "View changes" button of its own.

### Access Control

By default, **only users with the `admin` role** can access the plugin — that includes the Content Watch panel area, the menu entry, the diff API, and the restore API. Non-admins will not see the menu item, and any direct API call returns `403 Forbidden`.

To grant access to additional Kirby roles, list them in `allowedRoles`:

```php
'tearoom1.kirby-content-watch' => [
    'allowedRoles' => ['editor'],
]
```

Notes:
- Admins are **always** allowed; you do not need to include `'admin'` in the list.
- The `allowedRoles` check gates *access to the plugin*. A restore runs through Kirby as the current user and needs the same permissions as the change in the Panel: `update`, and `changeSlug`, `changeStatus` or `changeTemplate` when the restored version differs in these. A role allowed by `allowedRoles` cannot use restore to change anything it is not normally allowed to change.
- Restore and diff API requests are authenticated and only resolve history files inside Kirby's configured content root.
- Only grant `allowedRoles` to roles you trust to read every page's modification history, including drafts.

## How It Works

The plugin creates a `.content-watch.json` file in each content directory that has been modified, with `detectExternal` in every content directory (see Changes Outside the Panel). This file stores the history of changes including editor information, timestamps, and content snapshots for restoration (if restore is enabled).
History entries are automatically pruned based on your retention settings (see Retention).

When restore functionality is enabled:
1. Each time content is changed, a snapshot of the content is saved
2. You can view and restore previous versions through the interface
3. When you restore a previous version, the plugin runs Kirby's own actions as the current user: `changeTemplate`, `changeSlug` and `changeStatus` when the version differs, then `update` with the content of the version. Permissions, blueprint validation, hooks and the pages cache apply as for any change in the Panel. Everything that can be checked upfront (permissions, a URL appendix used by another page, unsaved changes) is checked before anything changes; if a Kirby action still fails, the steps done so far are undone.
4. Restoring a translation only restores the content and the translated URL appendix of that language. Template, folder name and status follow the default language. The fields are written exactly as the version stored them: a translation only gets the fields its own file had, so values shown from the default language (fallbacks) and untranslatable fields are never copied into it, and blocks keep their stored formatting.
5. The restore is added to the history as a new version that refers to the restored one. Older versions stay as they are, so you can go back.

Changes are recorded in the language that was written, also when code updates a translation (e.g. `$page->update($data, 'de')` from a translation plugin while the request runs in English); one update that writes several languages adds one entry per language.

The history file is changed under a lock and replaced atomically, so parallel saves keep all entries. A history file that can't be read is moved aside as `.content-watch.corrupt-<time>.json` and a new history starts, saving content never fails because of it.

When restore functionality is disabled:
1. The plugin only tracks metadata like timestamps and editor information
2. No content snapshots are stored, reducing disk usage
3. The restore buttons will not be displayed in the interface

> NOTE: The restore functionality only works for page content.
> It does not track changes of media/binary files.
> And when restoring a page, it does not restore its files.


### History Field

Show the recent changes of a page, the site or a file right where editors work by adding the `contentwatch` field to its blueprint:

<img src="screenshot-section.jpg" alt="History field in a page sidebar" width="468">

```yaml
fields:
  history:
    type: contentwatch
    label: Recent changes # optional
    limit: 5              # optional, number of entries
```

The field lists version, editor, time and language of each change and links to the full history in the Content Watch area. With `enableRestore` (and `enableDiff`) turned on, each change can be compared with the version before it and older versions can be restored right from the field. It is only visible to users who may access the plugin (see Access Control). The field stores nothing in the content file.

#### Upgrading from Kirby 5

Version 4 of this plugin requires Kirby 6 and PHP 8.3 or newer; for Kirby 4 and 5 keep version 3. Options, history files and snapshots stay the same, so the history of a site carries over.

Update Kirby and the plugin together, since version 3 refuses Kirby 6 and version 4 refuses Kirby 4 and 5:

```bash
composer require getkirby/cms:^6.0 tearoom1/kirby-content-watch:^4.0 --with-all-dependencies
```

Without Composer, replace the `kirby` folder and the plugin folder in the same step.

Kirby 6 no longer supports custom sections, so the `contentwatch` section of earlier versions is a field now. Move it from `sections` to `fields` in your blueprints; the type name stays `contentwatch`:

```yaml
# Kirby 4 and 5
sections:
  history:
    type: contentwatch
    headline: Recent changes

# Kirby 6
fields:
  history:
    type: contentwatch
    label: Recent changes
```

Kirby 6 shows a blueprint that still lists it under `sections` with an "Invalid section type" message in its place. `headline` keeps working as an alias of `label`. As before, the heading is "Recent changes" unless you set one; a `label` that only repeats the field name (e.g. `label: History` for a field called `history`) counts as not set.

### Page Method

The plugin exposes a `contentHistory()` method on all pages, giving you programmatic access to the change history from within templates or plugins.

```php
// Get all history entries for the current page
$history = $page->contentHistory();

// Filter by language (multilang setups)
$history = $page->contentHistory('en');
```

Each entry is an array with the following keys:

| Key | Description |
|-----|-------------|
| `version` | Incrementing version number |
| `time` | Unix timestamp of the change |
| `editor_id` | ID of the Kirby user who made the change, `null` for a change outside the Panel or a starting point |
| `source` | `external` for a change outside the Panel (see `detectExternal`), `baseline` for a starting point (see Starting Points), otherwise not set |
| `type` | Always `page` for page content |
| `language` | Language code (multilang only, requires `enableRestore`) |
| `content` | Raw content snapshot (only present when `enableRestore` is `true`) |

**Example: display last editor info**

```php
$history = $page->contentHistory();
if (!empty($history)) {
    $latest = $history[0];
    $editor = kirby()->user($latest['editor_id']);
    echo 'Last edited ' . date('Y-m-d H:i', $latest['time']);
    echo ' by ' . ($editor ? $editor->name() : $latest['editor_id']);
}
```

**Example: access field values from a snapshot (requires `enableRestore: true`)**

```php
foreach ($page->contentHistory() as $entry) {
    $fields = isset($entry['content'])
        ? new \Kirby\Cms\Content(\Kirby\Data\Txt::decode($entry['content']), $page)
        : null;

    echo 'v' . $entry['version'] . ' — ' . date('Y-m-d H:i', $entry['time']);

    if ($fields) {
        echo ' — title: ' . $fields->title()->html();
    }
}
```

This gives you full Kirby field method access (`.html()`, `.kirbytext()`, `.value()`, etc.) on any historical snapshot.

### Comparing Versions

With `enableRestore` and `enableDiff` turned on, each history entry has a "View changes" button. It shows what that version changed, compared with the version before it in the same language; the oldest version has no button. Both in the Content Watch area and in the history field, the dialog switches between two comparisons:

- **Previous version**: what the shown version changed.
- **Current version**: the shown version against the content as it is now, e.g. everything that changed since your own edit a few days ago.

"Older" and "Newer" (or the left and right arrow keys) step through the versions in the chosen comparison. In the Content Watch area, the "Older" and "Newer" selects also allow any other pair. The restore button of an entry opens the dialog with the comparison to the current version.

- Only changed fields are shown, with the field label from the blueprint and the raw key next to it. A summary line tells how many fields changed and how many unchanged fields are hidden.
- Inside changed lines only the changed words are marked. Long texts show the changed lines with one line of context.
- Blocks and layouts are compared block by block (matched by their id): each changed, added, removed or moved block shows its type and position, changed blocks list their changed properties. Unchanged blocks collapse to one line. Structure fields are compared entry by entry.
- Files, pages and users show their names instead of their ids.
- Versions that equal the current content have no restore button.
- While comparing with the current version, users who may update the page can restore the compared version right from the dialog ("Restore this version", with a confirmation). Only the language of that version is affected; files and other languages stay unchanged.

The optional `jfcherng/php-diff` package is no longer used by the Panel.

## Requirements

| Kirby | Plugin version | Branch |
|---|---|---|
| 6 | 4.x (since 4.0.0) | `main` |
| 4 and 5 | 3.x (bug fixes only, latest 3.15.2) | `kirby-5` |

- PHP 8.3 to 8.6 (as supported by Kirby 6)

## Todo

- Add support for restoring media/binary files ?
- Language support is not perfect yet. Currently only 2 digit codes work and the display of the versions may also be improved.

## Support

Content Watch is free and MIT licensed, and it stays that way. If it saves you time, you can help keep it maintained. Paying is voluntary and any amount is welcome.

Agencies and companies that need an invoice can get a Supporter License. You choose the price and whether it covers one project or your whole company. Lemon Squeezy handles the sale as merchant of record, so you get a proper invoice with VAT. The license adds no features and no priority support.

[Support this plugin](https://www.tearoom.one/en/kirby-plugins/support)

## License

This plugin is licensed under the [MIT License](LICENSE)

## Credits

- Developed by Mathis Koblin
- Assisted by Claude (Anthropic)

## Screenshots

Screenshots use sample content at 1408 × 902 pixels. Panel views are cropped to the content area. The main image uses the light theme.

| View | Light | Dark |
| --- | --- | --- |
| Overview | [Light](screenshot.jpg) | [Dark](screenshot-dark.jpg) |
| Page history field | [Light](screenshot-section.jpg) | [Dark](screenshot-section-dark.jpg) |
