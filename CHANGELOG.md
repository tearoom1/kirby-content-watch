## [3.8.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.7.0...v3.8.0) (2026-10-23)


### Features

* highlight changed words inside changed lines of the diff ([cdb76b5](https://github.com/tearoom1/kirby-content-watch/commit/cdb76b5a121041a734995b555dd29a2fa9eb9314))
* show blueprint field labels instead of raw keys in the diff ([350b2f6](https://github.com/tearoom1/kirby-content-watch/commit/350b2f6e957ef9ade357bea218c4312ec5a63a40))
* show changes without a Panel user as "Outside the Panel" ([9bd4b14](https://github.com/tearoom1/kirby-content-watch/commit/9bd4b1492452719a30d3d407d40ec27453757da6))
* switch between compact and relaxed layout in the Panel ([7ce77cc](https://github.com/tearoom1/kirby-content-watch/commit/7ce77ccda88f99dc1445e8444ea4fe3b610e5898))


### Bug Fixes

* move tabs and support link into a compact row under the area title ([cb03e46](https://github.com/tearoom1/kirby-content-watch/commit/cb03e468e9143585caa64c731ff8664d054b6605))

## [3.7.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.6.0...v3.7.0) (2026-10-23)


### Features

* Panel support link opens the central support page ([ae14ff0](https://github.com/tearoom1/kirby-content-watch/commit/ae14ff051f0f45b8b5c84d6cbc9b05bbc6762317))

## [3.6.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.5.0...v3.6.0) (2026-10-08)


### Features

* compare and restore versions from the history section ([e7d61cf](https://github.com/tearoom1/kirby-content-watch/commit/e7d61cfa75971fa290f7512331190c61721d18ba))


### Bug Fixes

* refresh the history section after saving ([d215bb3](https://github.com/tearoom1/kirby-content-watch/commit/d215bb3379113830e475f0ddfd79ba925f61f97d))

## [3.5.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.4.0...v3.5.0) (2026-10-07)


### Features

* align history section with the new timeline design ([7e441c7](https://github.com/tearoom1/kirby-content-watch/commit/7e441c799e95d96c110fcd25af755588edfbe833))


### Bug Fixes

* make active tabs and toggles visible in light mode ([82dc67f](https://github.com/tearoom1/kirby-content-watch/commit/82dc67f76cc3daaf8f5d1fbac8c5857ca8c33a44))

## [3.4.0](https://github.com/tearoom1/kirby-content-watch/compare/v3.3.7...v3.4.0) (2026-10-07)


### Features

* add contentwatch blueprint section showing a model's recent changes ([ff21e63](https://github.com/tearoom1/kirby-content-watch/commit/ff21e636fb0236880dee054ae02f1571a1ab3e3b))
* apply retentionCount per language ([fb2d566](https://github.com/tearoom1/kirby-content-watch/commit/fb2d566f983d30ea5c06e5c6adb4042680441492))
* export filtered change history as CSV ([b649487](https://github.com/tearoom1/kirby-content-watch/commit/b649487230ac830e6eb1ae5f559e78688037e173))
* filter content changes by period and language ([cc34c50](https://github.com/tearoom1/kirby-content-watch/commit/cc34c50a62cccaeb490db3043cab311473411b80))
* hide locked pages view on Kirby 5 by default ([d25a495](https://github.com/tearoom1/kirby-content-watch/commit/d25a495bcb2e13eedf50ff68622dde0cc09e20cc))
* notify a webhook or closure on content changes ([28d50da](https://github.com/tearoom1/kirby-content-watch/commit/28d50da1ac7816914caac108cecf5a9e6905e23f))
* redesign history timeline per layout style ([cf9cea3](https://github.com/tearoom1/kirby-content-watch/commit/cf9cea302526b728112ac610f18fb257b808167f))
* rename layoutStyle 'default' to 'relaxed' ([b554366](https://github.com/tearoom1/kirby-content-watch/commit/b5543665c0699f38bbc8f62dd4b6463de670fac4))
* show the action as a colored tag in the compact timeline ([687a5cf](https://github.com/tearoom1/kirby-content-watch/commit/687a5cfa93628c09704db3f5af5039b2cbad169f))
* tighter timeline rows with single-line time in compact layout ([e6d1f21](https://github.com/tearoom1/kirby-content-watch/commit/e6d1f2140fac94ce42bce5a2d39df7be59454e8a))


### Bug Fixes

* apply period and language filters to exported CSV entries ([159344b](https://github.com/tearoom1/kirby-content-watch/commit/159344b461a71e786e3e4af68fd2301475f63a64))
* harden diff generation, reset history on duplicate, drop history of deleted files ([6e21c96](https://github.com/tearoom1/kirby-content-watch/commit/6e21c967e1c1582d3a59999c3ac8cb461ce11b36))
* hide history entries outside the retention window in the panel ([5b76b00](https://github.com/tearoom1/kirby-content-watch/commit/5b76b00b0c054a742a64d347d6d9e8ce949435bf))
* hide locked pages tab when enableLockedPages is disabled ([ae1e71d](https://github.com/tearoom1/kirby-content-watch/commit/ae1e71d6121dddc5ffa595b5fc016971db38ce81))
* ignore content folders without a Kirby model ([18e58d2](https://github.com/tearoom1/kirby-content-watch/commit/18e58d23cc2d9e4b5e3fcc44689f95f1b6850c5a))
* label icon-only buttons and drop empty page size option ([29edadb](https://github.com/tearoom1/kirby-content-watch/commit/29edadb14d48bf5d1e9eee2b64428b48c9998e43))
* polish history section icons and navigate inside the panel ([73423d9](https://github.com/tearoom1/kirby-content-watch/commit/73423d94b9b36003a504b42e880aaeeed31e4f1d))
* reject restore for directories without a content model ([8bc3514](https://github.com/tearoom1/kirby-content-watch/commit/8bc3514fcad927455532a70f1c11234e6dbdfc84))
* show a readable label for locks of unknown users ([cb844af](https://github.com/tearoom1/kirby-content-watch/commit/cb844af90e5cb2ccd856f105e45c828853260798))
* slightly more row spacing in compact timeline, align compact fallbacks ([fb9004f](https://github.com/tearoom1/kirby-content-watch/commit/fb9004fb608418dd65ea65909046f7a78cfd4141))
* tone down tab size and make the panel view usable on small screens ([ec734e6](https://github.com/tearoom1/kirby-content-watch/commit/ec734e6904c1aff95f17322ee8b6cae643db3866))

