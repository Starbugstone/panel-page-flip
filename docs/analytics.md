# Privacy-first Google Analytics 4

Google Analytics is optional and off by default. It runs when — and only when —
both of these are true:

```dotenv
GOOGLE_ANALYTICS_ENABLED=true
GOOGLE_ANALYTICS_MEASUREMENT_ID=G-PSW1MY7HB4
```

No AdSense account, publisher id or Privacy & Messaging configuration is
required. Analytics used to demand a valid `ADSENSE_CLIENT` because Google's
Privacy & Messaging was the only consent provider wired up; that made a
credential for one integration silently gate another, and it is fixed. A
missing or malformed measurement id disables Analytics and logs a warning; a
missing or malformed publisher id disables advertising and nothing else. No
build-time frontend value can override the server's answer from
`/api/public-config`.

## The four states

Advertising and Analytics are switched independently, and the consent owner is
derived from the result rather than configured separately — there is no
`CONSENT_PROVIDER` setting, because the only value it could hold that the two
feature flags do not already imply is a wrong one.

| `ADSENSE_ENABLED` + valid client | `GOOGLE_ANALYTICS_ENABLED` + valid id | Ads | Analytics | Consent owner |
|---|---|---|---|---|
| no | no | off | off | none — no optional Google script at all |
| yes | no | on | off | Google Privacy & Messaging |
| no | yes | off | on | this application's Analytics preferences |
| yes | yes | on | on | Google Privacy & Messaging, one dialogue for both purposes |

Two properties are deliberate and covered by tests:

- **A credential is not a switch.** A leftover `ADSENSE_CLIENT` with
  `ADSENSE_ENABLED=false` changes nothing: advertising stays off, consent stays
  local, and the publisher id is not published to the browser.
- **Failures are independent.** An invalid publisher id cannot disable working
  Analytics, and an invalid measurement id cannot disable working advertising.

`php bin/console app:diagnose-google-integrations` prints the resolved state,
which credential is valid, which provider owns consent, and which CSP profile
each Google-free route receives.

## Consent

### Analytics only

This application asks, with **Accept analytics** and **Reject analytics** side
by side and equally easy to choose. Nothing Google-owned is loaded before a
grant — that is basic consent mode, so a visitor who rejects sends no cookieless
pings either. On a grant, the application queues Consent Mode v2's
`ad_storage`, `ad_user_data`, `ad_personalization`, and `analytics_storage`
defaults before the GA4 configuration, then updates only `analytics_storage` to
granted. The advertising fields remain denied and ads-data redaction remains on.

The answer is stored in this browser's local storage under
`panel-page-flip:analytics-consent`, with a schema version so that changing what
the dialogue says can invalidate answers given to the old wording. Acceptances
and refusals expire after 180 days (approximately six months), following the
CNIL's normal re-prompt recommendation. It is the one copy: nothing else in the
application keeps a second opinion about consent.

Reopening is permanent, through **Analytics preferences** in every footer and
inside reader settings. Withdrawal takes effect immediately and removes the
`_ga` and `_ga_*` cookies for this site. It also sends a Consent Mode v2 update
that returns all four fields to denied before collection is disabled. Other open
tabs observe storage changes, so a withdrawal or cleared choice also stops
measurement there without requiring a reload, including while public
configuration is still loading.

### With advertising

Google's certified CMP is the one consent UI, and this application shows no
second dialogue. Before turning Analytics on, in AdSense **Privacy & messaging →
European regulations → Settings**, enable both **Consent mode for advertising
purposes** and **Consent mode for analytics purposes**, then publish the message
for the site. Google documents that the analytics-purpose option lets the
existing European-regulations message gather `analytics_storage`.

The consent observer and the permanent preferences control share one loader
arbiter. When AdSense site code is already in flight, both wait for that request
before considering the standalone Funding Choices script; the standalone path
is used only when no site-code request exists or that request becomes
unavailable without exposing the CMP.

If the account never grants the analytics purpose, Analytics stays off. That is
the intended failure: silently measuring somebody who did not agree would be
worse than losing the measurement.

The tag is not downloaded before the CMP reports `analytics_storage` as
`GRANTED`, or `NOT_APPLICABLE` because EU consent rules do not apply. `DENIED`,
`UNKNOWN`, `NOT_CONFIGURED`, a blocked CMP, an invalid response and a timeout all
leave Analytics off.

The observer removes its TCF listener on cleanup, including when registration
finishes after the observer has stopped. Late callbacks cannot register new
listeners or add stale consent work to the queue.

## GA4 account prerequisites

For either consent path:

1. Create a GA4 web data stream and copy its `G-` measurement id.
2. Turn off the web stream's **Enhanced measurement** master switch. The
   application sends only its own sanitized page views. History, scroll,
   outbound-click, site-search, video, file-download and form events can expose
   real application URLs or user-entered values and must all remain disabled.
3. Disable Google Signals, granular location/device collection, and advertising
   personalization unless a separately documented need and legal review justify
   them. Do not link the property to Google Ads by default.
4. Set user-level and event-level retention to two months and accept Google's
   Data Processing Terms for the operator.
5. Limit GA account access to named operators who need it.

The application loads `gtag.js` directly; it does not install a Google Tag
Manager container. Keep this single loader as the only GA4 deployment path so a
second tag cannot bypass the consent and route boundaries.

Google documents Consent Mode at
<https://developers.google.com/tag-platform/security/concepts/consent-mode>, the
AdSense CMP flags at <https://support.google.com/adsense/answer/16053245>, its
basic-consent callback at
<https://developers.google.com/funding-choices/fc-api-docs>, and manual SPA page
views at <https://developers.google.com/analytics/devguides/collection/ga4/views>.

For evidence of the first-party flow, retain a dated screenshot of each banner
version alongside the release that introduced its
`ANALYTICS_CONSENT_VERSION`. The stored record contains only the decision,
version, and decision time; no server-side consent profile is added just to
prove the interface existed.

## Google-free legal routes

`GooglePolicyBoundary` requests a fresh document whenever navigation crosses
between these routes and the rest of the application. CSP belongs to the
document; client-side routing cannot replace it or unload Google code already
running. Navigation within either policy group remains client-side. The privacy
choices button carries its request across that reload using a one-use query
parameter, which the consent provider removes before reopening the panel.

`/privacy`, `/cookies` and `/terms` load no AdSense site code, no Funding
Choices tag and no `gtag.js`, in every configuration, on direct load as well as
on SPA navigation. Google requires the privacy-policy URL configured in Privacy
& Messaging to host neither the consent-message tag nor any other script
requiring consent, so `<APP_URL>/privacy` is safe to configure there.

The rule is one shared list rather than three components each remembering it:

- `backend/config/frontend-routes.json` → `googleFree`, read by
  `FrontendRouteRegistry`;
- `frontend/src/lib/google-free-routes.js`, whose test fails if the two drift;
- `ContentSecurityPolicy` serves those routes the strict non-Google policy — no
  nonce, no `strict-dynamic`, and no Google origin in `img-src`, `frame-src` or
  `connect-src` — even on an installation with both integrations on;
- `scripts/generate-nginx-routes.mjs` bakes the same policy into the static
  nginx locations, so the container deployment enforces it too.

A privacy-choices control clicked from one of these pages navigates to a safe
route before opening Google's panel, rather than mounting Funding Choices where
it is not allowed.

## Privacy boundary

Only the fixed route categories in `frontend/src/lib/google-analytics.js` are
measured. Query strings are discarded. Collection is suspended before every
History API navigation, and the tag's context is replaced with the fixed safe
path, title and empty referrer before it resumes; this also contains GA's
automatic lifecycle events. The following never produce a page view:

- comic reader routes and reading activity;
- administration routes and user ids;
- password reset and email verification routes;
- share invitation routes and tokens;
- the legal-policy pages, which are Google-free entirely;
- `/report-content` — the reporting workflow is not measured, because a page
  view saying somebody opened it is measurement of a legally sensitive act that
  nothing here needs;
- unknown routes or user-entered paths.

No User-ID or custom user properties are set. Page titles are fixed application
labels, page locations are rebuilt from the configured public origin and safe
path, and no referrer is sent. Google Signals and advertising personalization
signals are disabled in code. Analytics cookies expire thirteen months after
first use and are not refreshed on each visit.

## Production verification

Use a fresh/incognito EEA session with blockers disabled. In the console:

```js
document.getElementById("adsense-site-code")?.src
document.getElementById("google-cmp")?.src
document.getElementById("google-analytics-tag")?.src
typeof window.googlefc
document.cookie
```

### Analytics only

1. Before choosing, confirm there is no request to `googletagmanager.com`,
   `google-analytics.com` or `fundingchoicesmessages.google.com`, no
   `google-analytics-tag` element, and no `_ga` cookie.
2. Reject analytics and confirm the same remains true while application features
   still work.
3. Accept analytics and confirm exactly one sanitized `page_view` per allowed
   route in GA DebugView.
4. Navigate through `/read/<id>`, `/admin`, a reset URL and an invitation URL;
   confirm none of their paths, ids, tokens, titles or query strings appear in
   DebugView or network payloads.
5. Open **Analytics preferences**, reject, and confirm `_ga`/`_ga_*` cookies are
   removed and later navigation sends no measurement requests.

### AdSense only

Confirm AdSense and the Google CMP behave as configured, and that no GA tag or
`google-analytics.com` request appears anywhere.

### Both

Confirm there is exactly one consent flow — Google's — with no local analytics
banner, and that GA starts only after the analytics purpose is granted.

### Neither

Confirm no optional Google script is requested at all.

### Legal pages, in all four modes

Direct-load `/privacy`, `/cookies` and `/terms` and confirm the Network panel
shows no request to `adsbygoogle.js`, `fundingchoicesmessages.google.com`,
`gtag.js` or `google-analytics.com`, and that none appears after timers and
effects settle. Check the response `Content-Security-Policy` on those routes
names no Google origin.

Also inspect CSP console reports and verify no user id, comic id, reset or share
token, query string or user-entered value reaches GA DebugView or a network
payload.

Ad blockers and consent refusal reduce measured traffic by design. Analytics is
an optional observation layer and no application feature waits for it.
