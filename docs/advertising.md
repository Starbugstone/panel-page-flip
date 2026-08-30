# Advertising, consent, and AdSense Offerwall

Advertising is optional and fail-open. The application loads Google only when
`ADSENSE_ENABLED=true` and `ADSENSE_CLIENT` is a valid `ca-pub-` id. Uploading,
reading, sharing and account features never depend on an ad being available or
on advertising consent.

Run the safe application-side diagnostic after changing configuration:

```bash
cd backend
APP_ENV=prod php bin/console app:diagnose-advertising
```

It reports effective enablement, publisher-id validity, whether `/ads.txt` is
expected, dotenv mode, the ad-safe routes, the native Offerwall integration and
the strict-CSP mode. It does not print the publisher id or any secret.

## Runtime configuration

The supported O2Switch/default deployment mode keeps production settings in:

```text
backend/.env.local
```

That file is ignored by Git and excluded by both FTP and rsync deployment. A
release does not overwrite or delete it. `scripts/build-release.sh` defaults to
`DEPLOY_CONFIG_MODE=server-local`, uses disposable build-only values, ships no
runtime dotenv file, and does not run `composer dump-env prod`. Advertising
therefore does not need to be duplicated in `scripts/.env.deploy`.

An operator may explicitly choose `DEPLOY_CONFIG_MODE=compiled` for a portable
release. That generates `backend/.env.local.php`. Symfony gives that compiled
file precedence and stops reading normal dotenv files; later edits to
`.env.local` will not take effect until `.env.local.php` is regenerated or
removed. The diagnostic warns when compiled mode is active.

Minimum host-local settings are:

```dotenv
APP_ENV=prod
APP_DEBUG=0
ADSENSE_ENABLED=true
ADSENSE_CLIENT=ca-pub-1234567890123456
```

The public configuration endpoint then returns:

```json
{"adsense":{"enabled":true,"client":"ca-pub-1234567890123456"}}
```

An invalid id disables advertising safely and logs a warning. When advertising
is active, `/ads.txt` returns Google's seller record derived from the same id.

## Advertising boundary

AdSense site code may load only on these application-owned routes:

```text
/
/login
/upload
/upload/bulk
```

`frontend/src/lib/advertising.js` is the source of truth. Everything not on the
allowlist is ad-free, including `/dashboard`, `/read/*`, `/sharing`, `/share/*`,
`/settings`, `/admin/*`, and `/upload/bulk/session`. The last route contains real
filenames and batch state; `/upload/bulk` is intentionally a separate clean
information page.

Mirror this boundary with AdSense page exclusions. Account-side exclusions are
a second safeguard, not a replacement for the application allowlist.

## Native Offerwall bulk flow

Panel Page Flip uses AdSense **Privacy & messaging → Offerwall → Rewarded ad**.
Google implements the rewarded format, decides whether inventory is available,
records completion and grants the configured entitlement — for this deployment,
a page-view grant counted in loads of `/upload/bulk`, set in the account rather
than here. The application does not call the H5-game `adBreak()` API and does
not create a fake completion signal.

The flow is:

```text
/upload/bulk          clean Offerwall target and normal Continue action
        ↓
/upload/bulk/session  ad-free upload queue
```

`BulkUploadEntryLink` is the only way in, and decides how. With advertising on
it is a plain anchor, so entering the gate is a real document load: Google's
messaging APIs expose no way to re-evaluate a message after a client-side route
change, so a router navigation would reach the gate without ever giving the
Offerwall a chance. One load of `/upload/bulk` is one such chance, and the unit
the entitlement below is counted in.

While `/api/public-config` is still loading, the link uses a router navigation
to the gate. If advertising is enabled, the site code is then loaded there for
the first time; a fast click cannot bypass the Offerwall target. **Once
advertising is confirmed off, the gate is skipped entirely** and the link is an
ordinary router navigation to the queue. The header's link back from the batch
screen also stays a router navigation and preserves its folder query, because
reloading or resetting that route mid-batch would lose the queue in flight.

There is no application-owned “watch ad” prompt, rewarded audit flag or
server-side reward session; the two-hour session that predated this design was
removed with the rest of it. Consequently no stale session can hide a failed
integration and no reset endpoint is needed. If the site code is blocked,
consent is refused, Google has no rewarded inventory, or Offerwall does not
render for any other reason, the normal Continue action remains available.

Configure the account exactly as follows:

1. In AdSense, open **Privacy & messaging → Offerwall** and create/publish a
   message for `starbugstone.com`.
2. Add one page inclusion for `/upload/bulk`.
3. Add a page exclusion for `/upload/bulk/session`. Exclusions override
   inclusions.
4. Enable the Rewarded ad choice and select the intended rewarded ad unit.
5. Set a **page-view** entitlement, at the smallest value offered. Page views
   rather than time because `/upload/bulk` is the only included URL and
   `/upload/bulk/session` is excluded, and Google confirms excluded pages do not
   count towards a visitor's threshold — so the only page view this installation
   produces is one load of the gate, which is one attempt to start a batch. An
   entitlement of *N* covers the next *N* loads before the Offerwall returns.

   One advertisement per batch is not reachable: grants are expressed as
   *additional* page views, so even the smallest still covers the next visit.
   Treat "every other batch" as the floor. The value stays in the account —
   nothing here can read it back, so a copy would only ever be the wrong one.
6. Set the metering threshold to the smallest value offered. Google does not
   document whether a threshold of *N* gates the *N*th or the *N+1*th page view,
   so confirm the real frequency in the account rather than against this page.
   For deterministic account-side testing use Google's Privacy & messaging
   testing flow/`fc=alwaysshow`; it cannot override an excluded URL.

Google no longer requires ordinary subdomains to be registered as separate
AdSense sites. The approved `starbugstone.com` site can serve code on
`comics.starbugstone.com`; do not add a duplicate subdomain site requirement.
See Google's [subdomain management change](https://support.google.com/adsense/answer/12170421)
and [site readiness guidance](https://support.google.com/adsense/answer/9261307).

## Consent

The AdSense European-regulations message is separately created and published
under **Privacy & messaging**. Auto Ads being enabled does not create it.
`AdSenseProvider` loads site code only on ad-safe pages. The permanent **Privacy
choices** footer action loads Google's consent platform without advertising on
other routes and calls `googlefc.showRevocationMessage()`.

Test from a fresh/incognito EEA session. Reject must remain as accessible as
accept, withdrawing consent must work, and refusal must not block application
features. Useful console checks are:

```js
typeof window.googlefc
document.getElementById("google-cmp")?.src
```

## Strict Content Security Policy

Google supports AdSense with a strict nonce-based CSP because its resource
domains change. A static, hand-maintained `script-src` origin list is not a
supported integration.

Advertising-enabled HTML uses a fresh per-response nonce and Google's documented
script shape:

```text
script-src 'nonce-<random>' 'unsafe-inline' 'unsafe-eval' 'strict-dynamic' https: http:
```

The compatibility tokens are present because Google explicitly documents them;
they are not an inferred wildcard relaxation. CSP3 browsers use the nonce and
`strict-dynamic`, so `unsafe-inline` and the scheme fallbacks do not authorize
untrusted script. Non-script directives retain the explicit audited origins in
`backend/config/csp.json`.

Reference: [Integrate the AdSense ad code with a Content Security Policy](https://support.google.com/adsense/answer/16283098).

On Apache/Symfony, `FrontendController` creates a cryptographic nonce, injects it
into every initial Vite script and emits the matching response header. Every HTML
route reaches it: `scripts/deploy/htaccess.dist` rewrites client-side paths to
`index.php` rather than serving the built `index.html` off disk, because a static
shell arrives with no policy and no nonce. The `.htaccess` deliberately adds no
CSP header of its own — `Header always set` replaces rather than merges, so a
second policy there would overwrite the nonce header and block every script.

Advertising-off Symfony responses keep the tighter `script-src 'self'` policy.
The nginx headers cannot: they are baked into the image and the container never
sees `ADSENSE_ENABLED`, so both nginx targets carry the advertising shape
unconditionally and use the per-request `$request_id` in the header and in the
`sub_filter` that nonces the script tags. Those targets are the local Docker
stack; the released deployments are Apache/Symfony.

After changing `backend/config/csp.json`, run:

```bash
node scripts/generate-csp.mjs
npm run check:csp --prefix frontend
```

For a new production policy, first deploy the candidate as
`Content-Security-Policy-Report-Only`, inspect real violations, then enforce it.
Do not grow a guessed list of Google script hosts.

## Auto Ads production diagnosis

No visible ad is not itself a deterministic failure: Auto Ads may find no
suitable placement or inventory. Check each layer separately.

1. **Application:** run `app:diagnose-advertising`; verify `/api/public-config`
   and `/ads.txt` agree.
2. **Browser script:** on `/` and `/login`, with blockers disabled, verify the
   request to `pagead2.googlesyndication.com/pagead/js/adsbygoogle.js` and check
   `document.getElementById("adsense-site-code")?.src`.
3. **CSP:** inspect the real console/report collector for blocked scripts,
   frames, connections or inline execution. Verify the initial script nonce
   matches the response header.
4. **AdSense account:** under **Ads → starbugstone.com → Edit/preview**, verify
   Auto Ads, desired formats, page exclusions and excluded areas. Confirm the
   preview recognizes code on `comics.starbugstone.com`.
5. **Fill:** once configuration, script, CSP and account state are healthy,
   treat lack of fill as Google's runtime decision rather than an application
   error.

Never click production ads to test them. Use Google's preview and Offerwall test
facilities.
