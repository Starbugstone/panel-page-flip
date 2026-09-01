# Social sign-in

Panel Page Flip supports Google sign-in through the OAuth 2.0 authorization-code
flow. It is off until both Google credentials are configured. Each self-hosted
installation supplies its own OAuth application; no shared Panel Page Flip
credential or frontend Google SDK is involved.

## Google Cloud setup

1. Create or select a Google Cloud project.
2. Configure the OAuth consent screen and application information.
3. Create an OAuth client with application type **Web application**.
4. Add the installation's exact callback URI:
   `https://example.com/api/auth/oauth/google/callback`.
5. For local development, add the exact localhost URI if needed, for example
   `http://localhost:8080/api/auth/oauth/google/callback`. Production callbacks
   must use HTTPS.
6. Put the client values in the host's ignored `backend/.env.local`:

   ```dotenv
   OAUTH_GOOGLE_CLIENT_ID=123456789-example.apps.googleusercontent.com
   OAUTH_GOOGLE_CLIENT_SECRET=replace-with-the-google-client-secret
   ```

7. Clear the Symfony cache or redeploy.
8. Confirm `GET /api/auth/providers` reports `{"google":true}`, then test a
   new signup, an existing linked login, connect/disconnect from Settings, and
   account deletion for a passwordless test account.

The callback URI is derived from `APP_URL` and must match Google character for
character. Empty credentials are the off switch; no separate feature flag is
needed. Never put the client secret in React, a committed dotenv file, a log,
or a screenshot. The shipped Nginx configuration omits query strings from
access logs so callback authorization codes are not recorded; configure any
additional CDN or upstream reverse proxy with the same rule.

## Account and security model

An external account is identified by `(provider, provider subject)`, using
Google's stable `sub` value. Email is stored separately as a display snapshot
and is never used to decide that an identity is already linked. A Google login
whose email matches an existing local account is refused until the person signs
in normally and connects Google from Settings.

First-time social users complete the same username and legal-acceptance step as
password registrations. The application stores no login-only access token,
refresh token, authorization code, or raw provider response. Google sign-in asks
only for `openid email profile`.

Google-verified Gmail and Workspace addresses can begin with a verified local
email. A third-party address on a Google account still receives the normal Panel
Page Flip verification email before it can hold a session. Social-only users can
use password reset at any time to add a local password.

OAuth state and the pending registration are session-bound, short-lived, and
single-use. Disconnecting the last sign-in method is refused. A passwordless
user deleting an account must reauthenticate with the connected provider within
five minutes; an ordinary session alone is not enough.

The persistence and flow are provider-neutral. Adding another consumer identity
provider means adding its provider client and profile mapping while reusing the
same identity table, onboarding, linking, audit, export, disconnect, and recent
reauthentication rules.

## Privacy

When social sign-in is used, Panel Page Flip stores the provider name, stable
provider account identifier, provider email snapshot, and linked/last-used
timestamps. These records appear in the account's personal-data export without
the provider subject or credentials, and are deleted with the account.
