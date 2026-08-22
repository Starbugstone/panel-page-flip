# Advertising, consent, and the rewarded bulk-upload gate

This installation can show Google AdSense advertising to help cover hosting
costs. It is off unless an operator turns it on, and it is arranged so that
turning it on cannot cost a user a feature, and cannot put an advertisement
beside a comic somebody uploaded.

Two rules drive every decision in this document:

1. **Advertising is an enhancement, never a dependency.** Missing configuration,
   an ad blocker, a failed script, a refused consent, no rewarded inventory —
   each of them leaves the whole application working.
2. **Once user-uploaded comic content is rendered, advertising must not be
   rendered on that page.** Google holds the publisher responsible for the page
   an advertisement appears on, and this application cannot vouch for the
   contents of every private library.

## Configuration: two settings

```env
ADSENSE_ENABLED=false
ADSENSE_CLIENT=
```

There is deliberately no third. Which ad formats run, which pages are excluded,
which areas are excluded and whether an Offerwall appears are all configured in
the AdSense account — putting them in `.env` as well would be the same decision
in two places, free to disagree.

Advertising is active only when `ADSENSE_ENABLED=true` **and** `ADSENSE_CLIENT`
holds a publisher id in Google's form: `ca-pub-` followed by sixteen digits.
Anything else logs

```text
WARNING: AdSense is enabled but ADSENSE_CLIENT is missing or invalid.
Advertising disabled; all application functionality remains available.
```

and leaves advertising off. `App\Service\AdvertisingConfiguration` is the single
place this is decided; the browser is told the outcome and never the inputs.

### What the browser is told

`GET /api/public-config` (public — the landing and login pages need it before
anybody has signed in):

```json
{ "adsense": { "enabled": true, "client": "ca-pub-1234567890123456", "testMode": false } }
```

The publisher id is public by design; it appears in the page code Google issues.
Nothing else about the account is exposed, and the frontend parses no
environment variables of its own.

### `ads.txt`

Served by Symfony from the configured publisher id, not committed as a file:

```text
google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0
```

A checked-in `ads.txt` is a copy of `ADSENSE_CLIENT` that nothing keeps in step,
and a stale one names somebody else as entitled to sell this domain's inventory.
With advertising off the path 404s, because an installation with no AdSense
account has no authorised seller to declare.

Both nginx configurations and `backend/public/.htaccess` route `/ads.txt` to
Symfony. A deployment on some other web server needs the equivalent rule.

## Where advertising may appear

`frontend/src/lib/advertising.js` holds an **allowlist**, and everything absent
from it is ad-free:

```text
/            landing
/login       sign in and sign up
/upload      the single-comic uploader
/upload/bulk the rewarded-access gate
```

The direction matters more than the contents. A denylist would leave every new
route one forgotten edit away from carrying an advertisement beside somebody's
artwork; an allowlist leaves it one deliberate edit away from being allowed to.

Explicitly ad-free, and not an exhaustive list: `/dashboard`, `/read/*`,
`/upload/bulk/session`, `/sharing`, `/share/*`, `/dropbox-sync`, `/settings`,
`/admin/*`, `/privacy`, `/terms`, `/cookies`, `/report-content`,
`/forgot-password`, `/reset-password/*`, `/email-verification`.

`/upload` earns its place only while it stays a plain uploader — a file picker,
the limits, and the selected filename. **If it ever grows a cover preview, a page
thumbnail, or anything read out of the archive, take it off the list.** The
matching is exact, which is why `/upload/bulk` may carry advertising while
`/upload/bulk/session` cannot.

### How the boundary is enforced

`AdSenseProvider` is the only place Google's site code is loaded. It:

- fetches the script the first time the user is on an ad-safe route, and never
  on any other — an installation whose users go straight to their library never
  loads it at all;
- loads it once, and treats a blocked or silent script as `unavailable` after
  five seconds rather than waiting for ever;
- sweeps `ins.adsbygoogle` and Google's frames off the page on every navigation
  into an ad-free route. A single-page application cannot unload a script, so
  once the site code has run on the landing page it is still resident when the
  reader opens a comic; the sweep is what keeps the boundary real.

Google-side page exclusions are configured **as well**. They are a second
safeguard, not a replacement — the application must be correct on an account
nobody configured.

## Consent

Consent is handled entirely by a Google-certified CMP (AdSense → Privacy &
messaging), which the site code installs. This application:

- never reads, stores, or synthesises a consent state — a second opinion about
  consent is worse than none;
- does not run a competing banner. The existing cookie notice is dismissal-only
  and, where advertising is on, says only what storage is used and points at the
  consent panel for the choice;
- offers a permanent **Privacy choices** entry in the footer of every page
  (`googlefc.showRevocationMessage()`), so consent can be changed or withdrawn
  long after the banner is gone. It is absent where advertising is off, because
  there is then nothing to revisit.

## The rewarded bulk-upload gate

Single-file upload is never gated. Somebody who does not want to watch an
advertisement uploads their collection one file at a time, and that is also the
fallback whenever a rewarded advertisement cannot be served.

Bulk upload is more expensive per click — concurrent uploads, archive
inspection, image extraction, metadata work — so entering it *may* offer a
rewarded advertisement:

```text
/upload/bulk           the gate: this application's own text and two buttons
/upload/bulk/session   the batch: filenames, progress, failures — always ad-free
```

The decision, in `resolveBulkUploadAccess`:

| Situation | Outcome |
| --- | --- |
| Advertising off or unconfigured | uploader opens |
| Site code blocked, failed, or silent | uploader opens |
| Session endpoint unreachable | uploader opens |
| A batch is already open | uploader opens |
| Advertising on **and** site code ready | the choice is offered |

Only the last row offers. Accepting records one batch server-side and opens the
uploader; declining goes to the single uploader. One advertisement covers the
whole batch, and a file that fails can be retried inside it without another.

### The session, and what it does not prove

`BulkUploadSessionService` keeps a per-account, cache-backed record that expires
after two hours or when the batch finishes, whichever comes first. It is
server-side because a `localStorage` flag is not a scope: cleared, copied or
edited it answers "may I skip the offer" with whatever suits, and it never
expires by itself.

**The honest limit:** AdSense's Offerwall implements the rewarded format itself
and publishes no completion callback — the `googlefc` API exposes consent,
ad-block and US-state surfaces, and nothing for rewarded entitlement. So
`rewarded: true` is what the browser reported, not something the server
verified. It is an audit note, and nothing treats it as permission:

- the upload endpoints do not consult it, and must not start to;
- `POST /api/upload/bulk/session` never refuses;
- the gate is an offer, not a lock. Someone who bookmarks
  `/upload/bulk/session` skips it, exactly as someone with an ad blocker does.

That is the specified design, not an oversight: bulk upload is a feature of this
application, and an installation with no advertising has to reach it unchanged.

## Content Security Policy

`docker/nginx_frontend/security-headers.conf` lists the Google origins AdSense
and the CMP fetch from, in `script-src`, `frame-src`, `img-src` and
`connect-src`. Named origins, no wildcards. They cost nothing on an installation
with advertising off, because nothing ever reaches for them.

**What is deliberately not shipped is `script-src 'unsafe-inline'`.** Auto Ads
and the CMP inject inline scripts, so an installation that turns advertising on
will need either that or a per-response nonce — and neither belongs in a header
that every self-hosted, ad-free deployment inherits. An operator enabling
advertising adds it themselves:

```nginx
# In security-headers.conf, extend script-src:
#   script-src 'self' 'unsafe-inline' https://pagead2.googlesyndication.com ...
```

Verify with the browser console after enabling: blocked-script CSP violations are
the usual reason Auto Ads render nothing on an otherwise correct setup. The
development configuration already allows inline scripts, because Vite needs them.

## Developing and testing

Advertising stays off in development, in tests, in review deployments, and in
ordinary self-hosted installations. No Google script is loaded, no consent panel
appears, and bulk upload opens directly.

**Google publishes no sandbox publisher id for AdSense on the web.** The
`ca-pub-3940256099942544` id that circulates is AdMob's mobile-SDK test
publisher and does not serve AdSense web inventory; there is nothing to paste
into `ADSENSE_CLIENT` for a trial run. What there is instead:

- **Automatic test mode.** Whenever `APP_ENV` is not `prod`, the runtime
  configuration reports `testMode: true`, which marks placements as test
  placements. A developer who puts their own publisher id in
  `backend/.env.local` to watch the integration work therefore records no
  impressions against their account — which matters, because impressions from a
  machine nobody visits is the kind of traffic AdSense suspends accounts over.
- **The invalid-configuration path.** Setting `ADSENSE_ENABLED=true` with an
  empty or malformed client exercises the warning and the fail-open behaviour
  end to end without contacting Google at all.
- **The route policy and the gate are covered by tests**
  (`frontend/src/lib/advertising.test.js`,
  `frontend/src/components/ads/AdSenseProvider.test.jsx`,
  `frontend/src/pages/BulkUploadGate.test.jsx`), so the boundary can be changed
  with something other than a manual sweep of every page.

## Production checklist

Application side:

1. Set `ADSENSE_ENABLED=true` and the production `ADSENSE_CLIENT`.
2. Confirm `https://<your-domain>/ads.txt` returns the record, not HTML.
3. Extend `script-src` with `'unsafe-inline'` or a nonce (see above).
4. Confirm the startup log carries no `ADSENSE_CLIENT is missing or invalid`
   warning.

AdSense account side — most of the behaviour deliberately lives here:

5. Add and approve the production domain.
6. Configure the European regulations message (Privacy & messaging → GDPR).
7. Configure Auto Ads conservatively: in-page/banner formats only; low density;
   vignette, anchor/sticky, side rails and ad-intent formats **off**.
8. Add page exclusions for every ad-free route listed above — at minimum
   `/dashboard`, `/read/*`, `/upload/bulk/session`, `/library/*`, `/share/*`,
   `/settings`, `/admin/*`.
9. Add excluded areas wherever Google proposes a placement close to a control.
10. Create the Offerwall rewarded-ad message and target it at `/upload/bulk`
    only — **not** at `/upload/bulk/session`, which shows uploaded filenames.
11. Add the exact `ads.txt` entry AdSense supplies, if it differs from the
    generated one.

Then verify:

12. From an EEA location with fresh browser storage: the consent message
    appears, rejecting is as easy as accepting, and no advertising request is
    made before a choice.
13. Rejecting consent still permits registration, sign-in, single upload, bulk
    upload and reading.
14. No advertisement on the reader, library, covers, comic details, sharing
    screens or the bulk-upload batch screen.
15. With an ad blocker on: the application behaves identically and bulk upload
    opens directly.
16. Check the AdSense Policy Center a few days after launch.
