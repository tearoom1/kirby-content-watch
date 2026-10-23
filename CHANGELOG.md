## [3.12.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.11.0...v3.12.0) (2026-10-23)

### What's new since 3.7

Versions 3.8.0 to 3.12.0 were released within one day. This is the summary of all of them, so you don't have to read each release.

#### New view and layout
- The area now uses Kirby's own view header: large title, tabs for "Content Changes" and "Locked Pages" (only when locked pages are enabled), and the "Support this plugin" button.
- New **timeline** view next to the existing by-page view. It lists all changes across the site, newest first, grouped by day. Changes made outside the Panel within a few minutes (e.g. a deploy) are grouped into one expandable row.
- The toolbar is calmer: search, a view switch (by page / timeline), filters, export and refresh. "Pages only / All files" moved into the filters, and the filters button shows how many filters are active.
- New option `defaultView` (`page` or `timeline`). The choice is remembered per user.

#### Diff
- Fields show their blueprint label instead of the raw key.
- Changed words are highlighted inside changed lines.
- Blocks, layouts and structures are compared entry by entry: added, removed, moved and changed blocks, with unchanged runs collapsed.
- References (`page://`, `file://`, `user://`) are shown as titles, filenames and names. Deleted ones are marked.
- Secret-looking values (API keys, tokens, passwords) are never shown, only "value changed (hidden)".
- The dialog compares with the current version by default and shows a short summary.
- Versions equal to the current content are marked with a muted diff button.

#### Restore
- Restore can be started right from the diff dialog, with a confirmation.
- Restore now runs through Kirby's own page actions as the current user. Permissions, blueprint validation, hooks and the page cache apply. A restore adds a new history entry instead of rewriting the history.
- Restoring a translation only touches that language and never renames the page folder.
- The beta note was removed.

#### Reliability
- The history file is written with a lock and atomically. A corrupt history file is moved aside instead of blocking the save.
- Changes made in code (e.g. `$page->update($data, 'de')`) are recorded for the language that was actually written.
- Changes without a Panel user are labelled "Outside the Panel". Deleted users are labelled "Deleted user".

#### Good to know when updating
- `layoutStyle` is deprecated and has no effect. The list is always compact.
- Restore needs the matching Kirby permissions (update, changeSlug, changeStatus, changeTemplate). A version that fails the current blueprint validation can't be restored as a published page.
- In Kirby 5, a page with unsaved changes can't be restored until they are saved or discarded.

#### Commits


### Features

* mark versions that equal the current content ([b5b8a2a](https://github.com/tearoom1/kirby-content-watch/commit/b5b8a2a321b44504cd3417adb1c5e724f3bec941))


### Bug Fixes

* align the chevron of deploy groups with the action column ([40663ec](https://github.com/tearoom1/kirby-content-watch/commit/40663ec823a2752c010db1ae2e56e7e8515a81dd))
* changed blocks always list their changes, secrets stay hidden ([e12c62b](https://github.com/tearoom1/kirby-content-watch/commit/e12c62bd834467880d7e54a9073cfeb230d13a16))
* keep diff and restore buttons in fixed columns ([b03455d](https://github.com/tearoom1/kirby-content-watch/commit/b03455df25f6a3256bfa2500b9e99cd46f1c9185))
* more room for titles in the timeline, no required marker on "Show" ([9021e44](https://github.com/tearoom1/kirby-content-watch/commit/9021e44b236c7b12c1794cdd3ca9fa031fdbdb2e))
* resolve references inside blocks and structures, mark deleted ones ([5fb2c6f](https://github.com/tearoom1/kirby-content-watch/commit/5fb2c6f043b471e80d50763a3893ad88d9680b36))
* stronger colours for changed lines in the diff ([61a8f6b](https://github.com/tearoom1/kirby-content-watch/commit/61a8f6be8a0923618763f63b86ca35cd2a0ecdef))
* versions equal to the current content keep a muted diff button ([76dfa85](https://github.com/tearoom1/kirby-content-watch/commit/76dfa85e9e73817f0aa2a92aafc8f0dd38bf0f62))

## [3.11.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.10.1...v3.11.0) (2026-10-23)


### Features

* calmer toolbar with the page/file switch inside the filters ([9c0fce9](https://github.com/tearoom1/kirby-content-watch/commit/9c0fce95834cd43ee40dd9e46dcceb97ea46c856))
* native Kirby view header with title, header buttons and tabs ([c863b9f](https://github.com/tearoom1/kirby-content-watch/commit/c863b9f59dc04fb1738ef92961cb5b1c382bb8aa))
* view switch moves into the toolbar of the content changes tab ([68aacf4](https://github.com/tearoom1/kirby-content-watch/commit/68aacf4b83e3b0dab51b7a5738c417731f7d6a45))


### Bug Fixes

* list restore buttons use the diff confirmation, align timeline columns ([02b5aec](https://github.com/tearoom1/kirby-content-watch/commit/02b5aeca8c271317e9de141d0e0510c9cdea83e5))
* restore translations without fallbacks, record the written language ([75e7b07](https://github.com/tearoom1/kirby-content-watch/commit/75e7b078f40479747db181b6b4436013676b4538))

## [3.10.1](https://github.com/tearoom1/kirby-content-watch/compare/v3.10.0...v3.10.1) (2026-10-23)


### Bug Fixes

* fill in translation placeholders and tidy the timeline layout ([26f62a1](https://github.com/tearoom1/kirby-content-watch/commit/26f62a140230e2a3c7b3f6ebdd2935bf27200a95))

## [3.10.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.9.1...v3.10.0) (2026-10-23)


### Features

* restore a version right from the diff dialog ([5301a62](https://github.com/tearoom1/kirby-content-watch/commit/5301a62e4dae974722883a3ab6e210e3397fec2c))
* switch between a by-page view and a timeline of all changes ([ab3dd51](https://github.com/tearoom1/kirby-content-watch/commit/ab3dd51cdc4149e597b37842c619dadfb76a3ec2))

## [3.9.1](https://github.com/tearoom1/kirby-content-watch/compare/v3.9.0...v3.9.1) (2026-10-23)


### Bug Fixes

* restore through Kirby and keep the history file safe ([322d7a7](https://github.com/tearoom1/kirby-content-watch/commit/322d7a7a210cb854a1258b5c64b7ce9a548a6be7))

