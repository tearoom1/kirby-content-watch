## [3.15.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.14.0...v3.15.0) (2026-11-15)


### Features

* diff shows what a version changed, switch to the current version in the dialog ([1c4d860](https://github.com/tearoom1/kirby-content-watch/commit/1c4d8608ff4f1781e98d285f5d9a0fd3d31a7ea7))

## [3.14.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.13.1...v3.14.0) (2026-11-15)


### Features

* record content changed outside the Panel (detectExternal option) ([98d61f2](https://github.com/tearoom1/kirby-content-watch/commit/98d61f28811894dcd6b621734caaa7731483c7ba))


### Bug Fixes

* before hooks return nothing, compare page structure once, re-read under the lock ([091f4b2](https://github.com/tearoom1/kirby-content-watch/commit/091f4b2e13317d17ff228a7990a516396706877b))
* no diff button on the newest version when it is the current content ([ca9d996](https://github.com/tearoom1/kirby-content-watch/commit/ca9d99673823c36597d0623d62b747bfc09978f7))

## [3.13.1](https://github.com/tearoom1/kirby-content-watch/compare/v3.13.0...v3.13.1) (2026-11-10)


### Bug Fixes

* stop loading the plugin's own vendor autoloader ([0f1c62a](https://github.com/tearoom1/kirby-content-watch/commit/0f1c62a8fb783c45ad43c7ffeb24961e70438f75))

## [3.13.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.12.0...v3.13.0) (2026-10-30)


### Features

* own tooltips instead of browser titles ([67bedcc](https://github.com/tearoom1/kirby-content-watch/commit/67bedcc8f05649e2f36fa3a0b5dac2b55723d417))

## [3.12.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.11.0...v3.12.0) (2026-10-23)


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

