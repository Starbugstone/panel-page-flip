# Privacy-first Google Analytics 4

Google Analytics is optional and off by default. It runs only when all of these
are true:

```dotenv
GOOGLE_ANALYTICS_ENABLED=true
GOOGLE_ANALYTICS_MEASUREMENT_ID=G-PSW1MY7HB4
ADSENSE_CLIENT=ca-pub-1234567890123456
```

`ADSENSE_ENABLED` may remain `false`. The publisher id is still required because
Google Privacy & Messaging is the certified consent platform for this
installation. A missing or malformed measurement id or publisher id disables
Analytics safely and logs a warning. No build-time frontend value can override
the server's answer from `/api/public-config`.

## Consent and account prerequisites

Before enabling the environment switch:

1. Create a GA4 web data stream and copy its `G-` measurement id.
2. In AdSense **Privacy & messaging → European regulations → Settings**, enable
   both **Consent mode for advertising purposes** and **Consent mode for
   analytics purposes**, then publish the message for this site.
3. Turn off the GA4 web stream's **Enhanced measurement** master switch. The
   application sends only its own sanitized page views. History, scroll,
   outbound-click, site-search, video, file-download and form events can expose
   real application URLs or user-entered values and must all remain disabled.
4. Disable Google Signals, granular location/device collection, and advertising
   personalization unless a separately documented need and legal review justify
   them. Do not link the property to Google Ads by default.
5. Set user-level and event-level retention to two months and accept Google's
   Data Processing Terms for the operator.
6. Limit GA account access to named operators who need it.

Google documents the CMP flags at
<https://support.google.com/adsense/answer/16053245>, its basic-consent callback
at <https://developers.google.com/funding-choices/fc-api-docs>, and manual SPA
page views at <https://developers.google.com/analytics/devguides/collection/ga4/views>.

## Privacy boundary

The tag is not downloaded before the CMP reports `analytics_storage` as
`GRANTED`, or `NOT_APPLICABLE` because EU consent rules do not apply. `DENIED`,
`UNKNOWN`, `NOT_CONFIGURED`, a blocked CMP, an invalid response, and a timeout
all leave Analytics off. This is basic consent mode: denied visitors send no
Analytics cookieless pings.

Only fixed route categories in `frontend/src/lib/google-analytics.js` are
measured. Query strings are discarded. Collection is suspended before every
History API navigation, and the tag's context is replaced with the fixed safe
path, title and empty referrer before it resumes; this also contains GA's
automatic lifecycle events. The following never produce a page view:

- comic reader routes and reading activity;
- administration routes and user ids;
- password reset and email verification routes;
- share invitation routes and tokens;
- unknown routes or user-entered paths.

No User-ID or custom user properties are set. Page titles are fixed application
labels, page locations are rebuilt from the configured public origin and safe
path, and no referrer is sent. Google Signals and advertising personalization
signals are disabled in code. Analytics cookies expire thirteen months after
first use and are not refreshed on each visit.

Opening **Privacy choices** disables collection immediately. A denied choice
keeps the tag disabled and removes `_ga` cookies for this site. The control is
available in every normal footer and inside reader settings, so withdrawal does
not require leaving the reader.

## Production verification

Use a fresh/incognito EEA session with blockers disabled:

1. Before choosing, confirm there is no request to `googletagmanager.com` or
   `google-analytics.com`, no `google-analytics-tag` element, and no `_ga`
   cookie.
2. Reject Analytics and confirm the same remains true while application features
   still work.
3. Accept Analytics and confirm exactly one sanitized `page_view` per allowed
   route in GA DebugView.
4. Navigate through `/read/<id>`, `/admin`, a reset URL and an invitation URL;
   confirm none of their paths, ids, tokens, titles or query strings appear in
   DebugView or network payloads.
5. Open **Privacy choices**, withdraw Analytics, and confirm `_ga` cookies are
   removed and later navigation sends no measurement requests.
6. Check the browser console and CSP reports for blocked Google endpoints.

Ad blockers and consent refusal reduce measured traffic by design. Analytics is
an optional observation layer and no application feature waits for it.
