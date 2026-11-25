## [4.1.1](https://github.com/tearoom1/kirby-content-watch/compare/v4.1.0...v4.1.1) (2026-11-25)


### Bug Fixes

* name the starting point in the page list when there is no change yet ([f5f56f6](https://github.com/tearoom1/kirby-content-watch/commit/f5f56f6832f4b589d7d646f397fb2f2345d7ada5))

## [4.1.0](https://github.com/tearoom1/kirby-content-watch/compare/v4.0.0...v4.1.0) (2026-11-25)


### Features

* show starting points and group them in the timeline ([020957c](https://github.com/tearoom1/kirby-content-watch/commit/020957c7f596c90e4860507866ffc9d0f4d82e46))
* store a starting point for content without history ([d3e40d4](https://github.com/tearoom1/kirby-content-watch/commit/d3e40d456f4bb2a0797a01366422d8c783bda8f5))

## [4.0.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.15.1...v4.0.0) (2026-11-25)


### ⚠ BREAKING CHANGES

* requires Kirby 6 and PHP 8.3. Blueprints must list the
contentwatch section under `fields` instead of `sections`.

Co-Authored-By: Claude <noreply@anthropic.com>

### Features

* Kirby 6 support ([5864e38](https://github.com/tearoom1/kirby-content-watch/commit/5864e380c8983c3a347718e0353640bfcc5e6d20))


### Bug Fixes

* apply the author filter to every row of the CSV export ([890973b](https://github.com/tearoom1/kirby-content-watch/commit/890973bb6ed1b50f9d750a47c71101b86a7e479b))
* compare history field entries with the previous version in the same language ([effb430](https://github.com/tearoom1/kirby-content-watch/commit/effb430d28c1f969b083be93d273c2bb36a6c11e))
* drop late history answers and close only the restore dialog ([85b0532](https://github.com/tearoom1/kirby-content-watch/commit/85b053266a0e05b6ff218fd2003789441fb13e4e))
* keep the tooltip while hovered or focused and stop its Escape ([286cf4d](https://github.com/tearoom1/kirby-content-watch/commit/286cf4d1f5d568dee791c0e499265b744835d1e4))
* list every editor of the history in the author filter ([d49688c](https://github.com/tearoom1/kirby-content-watch/commit/d49688ca736bb803cf5421af2ee696e0534d9b62))
* refresh the history after a restore when the compare dialog was closed ([43e87d1](https://github.com/tearoom1/kirby-content-watch/commit/43e87d1aa3989f396ca9e72eed8b002a168a8088))
* restore the filter value helper in the history view ([39018b6](https://github.com/tearoom1/kirby-content-watch/commit/39018b68a2e8d0ab14dc953a4e0611c1762c8602))
* show a translated text when no change matches the search in the page view ([d5b8d96](https://github.com/tearoom1/kirby-content-watch/commit/d5b8d964737748831c9b790cf6dc2c2a7f72c83e))
* show names for Kirby 6 picker fields in the diff ([f9c97e7](https://github.com/tearoom1/kirby-content-watch/commit/f9c97e7a74da809fce01f74b4ce6b5ed452d9f4f))
* show tooltips above dialogs and drawers ([8c981ee](https://github.com/tearoom1/kirby-content-watch/commit/8c981ee758275b46aa71b86bf0314fec34a07296))
* skip versions that cannot be compared when stepping in the field diff ([3e1f29f](https://github.com/tearoom1/kirby-content-watch/commit/3e1f29f7ae39dbd4a0a913f15d182b33f820a640))
* the diff dialog of the field shows its result on Kirby 6 ([16fc522](https://github.com/tearoom1/kirby-content-watch/commit/16fc52268aec398a7586a6bff70cc78ca6dcba41))
* use Kirby 6 color and size tokens ([fbab296](https://github.com/tearoom1/kirby-content-watch/commit/fbab2960f3bf1fd7c6b898525a76f4f26af5b7cf))

## [3.15.1](https://github.com/tearoom1/kirby-content-watch/compare/v3.15.0...v3.15.1) (2026-11-15)


### Bug Fixes

* no duplicate current version in the diff selects, no jumping while stepping ([f52a484](https://github.com/tearoom1/kirby-content-watch/commit/f52a48482ba2f27ab38f33c4dd4f62f2c836ed51))

## [3.15.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.14.0...v3.15.0) (2026-11-15)


### Features

* diff shows what a version changed, switch to the current version in the dialog ([1c4d860](https://github.com/tearoom1/kirby-content-watch/commit/1c4d8608ff4f1781e98d285f5d9a0fd3d31a7ea7))

