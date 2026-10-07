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

## [3.3.7](https://github.com/tearoom1/kirby-content-watch/compare/v3.3.5...v3.3.7) (2026-09-02)


### Bug Fixes

* register content model resolver trait ([ca122ce](https://github.com/tearoom1/kirby-content-watch/commit/ca122ce394ca6e8d1879634705242a57e260b2da)), closes [#12](https://github.com/tearoom1/kirby-content-watch/issues/12)
* updated composer, bumped Kirby version to 5.5.3 ([88f731d](https://github.com/tearoom1/kirby-content-watch/commit/88f731d135b245838356990fdef40a21af839bbd))

## [3.3.5](https://github.com/tearoom1/kirby-content-watch/compare/v3.3.4...v3.3.5) (2026-06-10)


### Bug Fixes

* harden content watch API access ([1c78295](https://github.com/tearoom1/kirby-content-watch/commit/1c78295f2f4bd59abf296f7908a546af2c7c4413))

## [3.3.4](https://github.com/tearoom1/kirby-content-watch/compare/v3.3.3...v3.3.4) (2026-06-10)


### Bug Fixes

* added sponsor heart and css updates ([b578510](https://github.com/tearoom1/kirby-content-watch/commit/b57851025862cc256422f17f7eccbca652e4031a))
* gate restore, diff, and area with role-based access control ([2281020](https://github.com/tearoom1/kirby-content-watch/commit/2281020dcb5482677fdabec4f36f541a58154c45))

## [3.3.3](https://github.com/tearoom1/kirby-content-watch/compare/v3.3.2...v3.3.3) (2026-06-02)


### Bug Fixes

* upgraded composer dependencies ([89a0d44](https://github.com/tearoom1/kirby-content-watch/commit/89a0d4465543b797bc05a0d9ff5ade6192412d72))

