# Release and performance review, September 2026

## Scope

The release under review is [PR #211](https://github.com/Starbugstone/panel-page-flip/pull/211),
`develop` into `main`. The baselines were `main` at `8d6da79` and `develop` at
`3f791ca`. Work continues in the user's primary checkout on
`codex/performance-simplicity-review-20260904`, as requested.

The review combines the release diff, existing architecture/security reviews,
repository-wide coverage/static-analysis/dead-code/duplication gates and manual
tracing of uploads, source validation, covers, page delivery, geometry,
preloading, library state and reader navigation. The release checks also retain
sharing redaction/ownership, bounded metadata integers, session response
ordering and the consent/CSP boundaries added on develop. This is not a claim
that every possible runtime path or production workload has been exercised.

## Confirmed findings and changes

| Finding | Result |
| --- | --- |
| Library covers sent the original first-page image | Up to 800-pixel WebP covers for existing and new uploads; warm cached covers stream from disk |
| Upload client ignored half of the permitted chunk size | Server-advertised chunks up to 2 MiB, with unchanged concurrency and a 1 MiB compatibility fallback |
| A failed chunk left sibling workers running | Abort remaining work immediately and preserve the actionable server error |
| Every chunk progress event rebuilt the queue's row trees | Byte-based progress only emits changed percentages; unchanged rows skip rendering |
| Both upload forms accepted repeated clicks while validating the session | Disable immediately, keep the active upload cancellable, and unlock on an expired session |
| Cancelling preloads could drain a stale queue ahead of the destination page | Clear obsolete work before cancellation and retain each replacement queue's loader and variant |
| Thumbnail observation was registered before the observer existed | Observe mounted slots; load newly visible ones and release off-screen image elements |
| Thumbnail bursts competed with reading | Reuse the four-request cover budget and request low priority; retain a bounded nearby window |
| Single-page reads inspected unrelated source pages | Skip their manifest; defer spread geometry until the visible image arrives |
| Reader entry fetched the full library for an end-of-book feature | Defer recommendations until the final three pages and abort abandoned requests |
| Newly visible continuous pages briefly downloaded a default image size before the measured one | Wait for the first container measurement before assigning the image URL |

The upload failure, chunk sizing, repeated submission, preload cancellation,
preload variant, thumbnail observation and initial image sizing regressions
were run against the original implementation and failed before their fixes.

## Simplicity and test decisions

Existing source-provider strategies, `ComicVoter`, the shared serializer, bounded
page variants and cache abstractions remain the useful design boundaries.
`ComicCoverService` composes the existing encoder, cache and lock services;
controllers retain authorization and HTTP semantics. There is no new image
library, service worker, persistent client blob store or generic upload framework.

Five redundant cases were removed or consolidated: three byte-formatting cases
already covered in `format.test.js`, the duplicated configuration endpoint setup,
and the separate thumbnail-format test whose assertions now live in the paced
loading scenario. New tests cover failure ordering, byte progress, scrolling,
cache reuse and invalidation, bounded source reads, and deferred recommendations.
Reducing a test count is not a reason to remove an active security regression.

## Verification

Baseline frontend: 1,547 passing tests, 84.37% lines, 82.53% statements,
79.98% functions and 76.78% branches. Baseline backend: 1,550 tests,
6,123 assertions, one existing conditional skip, 86.74% lines and 77.14% methods.
Coverage thresholds and all-source inclusion are unchanged.

Final frontend: 1,550 passing tests, 84.62% lines, 82.72% statements,
80.22% functions and 76.93% branches. Five redundant cases were consolidated;
the new regressions mean the overall suite has a small net increase.

Final backend: 1,551 tests, 6,142 assertions and the same conditional skip;
86.77% lines and 77.18% methods. The backend comparisons start with the Symfony
test container cache cleared, since building that cache executes service
registration code that a warm run does not cover.

Local checks passed: PHPStan, PHP style, Composer validation and production
audit, Symfony container/Twig/schema validation, frontend lint, production npm
audit, both dead-code checks, duplication, routes, CSP, conversion downloads,
ShellCheck, the Unix conversion suite, production build and SEO metadata with
the same `APP_URL`. The Windows conversion suite requires its Windows CI runner;
PowerShell is unavailable locally. No conversion scripts changed.

Real Chromium checks used a synthetic 40-page, 44,138,022-byte CBZ in the local
demo account and inspected desktop and 390-pixel mobile layouts:

- Upload sent 22 chunks, compared with 43 at the previous 1 MiB setting; at most
  four chunk requests overlapped.
- Its 1,313,696-byte JPEG first page became a 387,726-byte, 800-pixel WebP cover,
  a 70.5% reduction in image bytes. The source archive was preserved.
- Reader entry requested the visible image before spread geometry and deferred
  the full catalog. Background page requests used the bounded preload window.
- The thumbnail strip retained all 40 navigation buttons, requested at most four
  thumbnails concurrently, loaded distant thumbnails when scrolled to them and
  removed images outside its margin.
- Mobile continuous reading kept three nearby images initially and five after
  scrolling, released the old images, and reused previously viewed images
  without new network requests on return. Newly revealed pages requested only
  the measured mobile variant after the sizing fix. A 1,920-pixel desktop
  viewport also requested the measured page width rather than the window width.
- No horizontal document overflow at 390 pixels and no browser console errors
  were observed.

## Operational limits

Cold image conversion still consumes CPU. Larger chunks reduce request overhead,
not source validation, disk throughput or the user's network transfer cost.
Continuous reading still measures bounded batches of page geometry; the
end-of-comic catalog is still a full metadata list when requested. Further
changes there should use measurements from a representative large library.

The review preserves the session handler and quota admission locks. Removing
those to increase apparent parallelism could break session updates or concurrent
quota enforcement. The local fixture verifies behavior and request counts;
it does not establish production latency for all archive formats or devices.
