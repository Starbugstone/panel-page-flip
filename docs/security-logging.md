# Security and audit logging

This is the operator's guide to the security/audit logging layer: where records
go, how long they are kept, who deletes them, and what makes an administrator's
phone buzz. It also states the rules a contributor has to follow when adding a
new event.

## What this feature is for

Two questions, and they need different answers.

1. **"What happened to this account/comic/share?"** — a trail of successful,
   security-relevant state changes that can be replayed in order. That is the
   **audit** stream.
2. **"Is somebody attacking us?"** — refusals, abuse and things that should not
   be possible through the interface. That is the **security** stream.

Ordinary application logging — errors, warnings, Doctrine, framework noise —
stays exactly where it was, on the `main` channel. Nothing in this feature
changes it.

**Reads are not logged.** No comic view, no page turn, no cover fetch. An audit
log that records what people looked at is a behavioural profile with a retention
period, and this application has no use for one.

## Channels

| Channel | Purpose | Written by |
|---|---|---|
| `app_security` | Refusals, suspected abuse, integration credentials that stopped working, data-integrity failures | `App\Service\SecurityAuditLogger` only |
| `app_audit` | Successful state changes worth reconstructing later | `App\Service\SecurityAuditLogger` only |
| `main` | Everything else | The rest of the application |

### Why not simply `security`?

Because `security` is already Symfony's own channel. The security component logs
its authenticator tracing there, including a `UserNotFoundException` whose
message quotes **the address that was submitted**. Reusing the name would pipe
every address an attacker typed into the login form straight into the file this
feature is otherwise careful never to write one to, and would bury the events
that matter under per-request framework chatter. The channels are therefore
`app_security` and `app_audit`; the files and directories are still called
`security` and `audit`.

## Where the files go

Daily files, ISO-dated. Security and audit files are grouped into `YYYY/MM/`
folders, because a year at one file per day is several hundred entries in a
single listing.

```text
backend/var/log/
  app/
    2026-08-08.log
    2026-08-07.log
  security/
    2026/
      08/
        2026-08-08.log
      07/
        2026-07-31.log
  audit/
    2026/
      08/
        2026-08-08.log
```

Rotation happens at the calendar-day boundary and is keyed off the *record's*
timestamp, not the clock — a record written at 23:59:59 lands in that day's file
even if the handler flushes it after midnight. Month and year folders are
created as they are needed.

In **production**, ordinary application logs go to **both** `php://stderr` (for
whatever collects container output) and `var/log/app/`. Security and audit
records go to their files **only**, and are not behind the `fingers_crossed`
handler — that handler holds everything back until an error arrives, which is
right for debugging a failure and wrong for an audit trail, where a successful
role change would otherwise never be written at all.

### If your deployment collects logs elsewhere

If the container filesystem is ephemeral and only `stderr` is collected, then the
files above are not authoritative and **the same policy must be reproduced in the
collector**: one stream per day, 30 days for application logs, 365 days for
security and audit records. Decide which mode you are in and write it down;
"the files are there" and "the files survive a redeploy" are different claims.

## Retention

| Stream | Kept for | Deleted by |
|---|---|---|
| `var/log/app/` | `APP_LOG_RETENTION_DAYS` (default 30) | `app:cleanup-logs` |
| `var/log/security/` | `SECURITY_LOG_RETENTION_DAYS` (default 365) | `app:cleanup-logs` |
| `var/log/audit/` | `AUDIT_LOG_RETENTION_DAYS` (default 365) | `app:cleanup-logs` |
| `admin_audit_log` table | 12 months | `app:cleanup-personal-data` |
| Unanswered `ComicShare` invitations | Until `expiresAt` (2 months) | `app:cleanup-expired-shares` |
| `share_claim_code` rows | 30 days past expiry | `app:cleanup-expired-shares` |
| Revoked `ComicShare` rows | 30 days past `revokedAt` | `app:cleanup-expired-shares` |
| `ComicShare.senderResponsibilityAcceptedAt` / `adultConfirmedAt` | Lifetime of the share record | Nothing — see below |

Deletion is **not** automatic. A `max_files` setting nothing enforces is how an
instance ends up believing it keeps a year of security logs while in fact
keeping everything forever. Schedule the command:

```cron
# daily, deploy user
15 3 * * * cd /var/www/comics/backend && php bin/console app:cleanup-logs --env=prod >> /var/log/comics-cleanup.log 2>&1
```

This is one of three commands a production instance must have scheduled — the
others apply the personal-data and sharing retention above. The full list, with
what breaks if each is missing, is in
[SSH-deploy.md §7](../SSH-deploy.md#7-background-jobs-cron--systemd-timers).

`--dry-run` reports what would go without removing anything. Expired files are
removed along with the month and year folders they empty; the stream root stays,
because the handler expects to be able to write into it.

A file's age is read from its **name**, never its modification time. A restored
backup, a copy or a late flush all touch a file without making its contents
younger, and retention that trusted the mtime would quietly stop meaning what it
says.

### The 18+ acknowledgements are not logs

`senderResponsibilityAcceptedAt` and `adultConfirmedAt` live on the `ComicShare`
row. They are the canonical evidence that an acknowledgement was made, and they
are better evidence than any log line because they are attached to the record
they are about and were generated server-side. The audit events
(`audit.share.sender_responsibility_accepted`, `audit.share.adult_confirmed`)
exist so the sequence can be read in order — they are a convenience, and nothing
in log retention can reach the timestamps.

No log in this feature is permanent by default, and none should be made so
without a written retention policy to point at.

## Never put these in a log

The redaction processor (`App\Monolog\SensitiveDataProcessor`) runs on **every**
channel and removes context entries whose key names a secret — `password`,
`token`, `secret`, `authorization`, `cookie`, `api_key`, `client_secret`, hashes,
and so on — recursively, at any depth. It also rewrites secrets embedded inside
strings: `?token=…`, `Authorization: Bearer …`, Dropbox `sl.…` tokens.

A key whose **last word** counts, times or otherwise describes something is
left alone —
`reset_tokens_deleted`, `token_count`, `password_changed_at`. Those name a
credential without holding one, and redacting them would strike out the very
numbers a retention or audit record exists to show. The exception is the final
word only: `token_count_value` is still redacted.

**Do not rely on it.** It is the backstop, not the rule. It cannot know that a
free-text string is an invitation URL, and it deliberately does not walk
exception objects. When adding a logger call, pass identifiers:

- account **ids**, not addresses (an address in a log is a phishing list);
- comic and share **ids**, not titles, filenames, tags or cover paths —
  especially for explicit content, whose title is the thing the age gate exists
  to withhold;
- counts and booleans, not the request body.

Specifically never: plaintext passwords, password hashes, reset tokens,
verification tokens, invitation/share tokens or URLs, session ids, cookies,
`Authorization` headers, CSRF tokens, Dropbox access/refresh tokens, OAuth codes,
any third-party API key, SMTP or database credentials, raw request bodies from
authentication endpoints, or comic bytes.

## Administrator email alerts

Alerts are an explicit decision by a caller, never a Monolog handler wired to
`warning`. Mailing every warning is how an alert channel becomes something people
filter out — and worse, it hands an attacker a mail cannon, because anything
repeatable from outside would generate one message per attempt.

```dotenv
SECURITY_ALERTS_ENABLED=0
SECURITY_ALERT_EMAILS=
SECURITY_ALERT_WINDOW_MINUTES=15
SECURITY_ALERT_FAILED_LOGIN_THRESHOLD=10
SECURITY_ALERT_AUTHZ_THRESHOLD=10
```

- **Off by default.** An instance with no reachable mailbox should not be trying
  to send these; every event is logged either way. Turn it on once
  `SECURITY_ALERT_EMAILS` is set and `MAILER_DSN` really delivers.
- **Prefer an operational address** over an administrator's personal one. If the
  list is empty, verified `ROLE_ADMIN` account addresses are used as a fallback —
  but an administrator account may be the very thing that has been taken over.
- **One message per recipient.** Never a shared `To:`; a security notification is
  the wrong place to publish who administers the instance.
- **Delivery never affects the operation.** A send failure is logged as an error
  and swallowed. A password really was changed whether or not the notification
  about it left the building. Each recipient is attempted separately, so one
  stale address does not silence the alert for everybody after it.
- **Thresholds are decided under a lock** (`symfony/lock`, `LOCK_DSN`). Counting
  and claiming the cooldown have to be one step, or two concurrent requests both
  cross the threshold and both send. If the lock store is unavailable the work
  still runs unserialised: a duplicate message is a better failure than an alert
  nobody receives.

## Nothing here may break what it observes

Every record is written alongside an operation that has usually already
succeeded. `SecurityAuditLogger` therefore contains its own failures — an
unwritable log directory, an unreachable cache, a mailer that throws — and
reports them on the ordinary application channel instead of letting them escape.

This matters because the failure modes are precisely the conditions worth
logging. Without it, a full disk would turn a completed password change into a
500 and invite the user to do it again.

Call sites do not need their own guards, and should not add them.

### To silence alerts during maintenance

Set `SECURITY_ALERTS_ENABLED=0` and reload. Logging continues unchanged, and a
suppressed alert leaves an `info` line saying so — "why did nobody get told" has
a findable answer.

### What alerts immediately

Serious on first occurrence: `ROLE_ADMIN` granted or removed (including an admin
account being deleted, or one created as an admin), destructive operations that
half-failed, a bulk deletion above 25 comics, orphan quarantine that could not
move files, an account deletion that failed after partial cleanup, and retention
cleanup that did not complete.

### What alerts on a threshold

Counted per event **and per source**, within a sliding window, so one noisy client
cannot hide a second one behind it:

| Event | Threshold |
|---|---|
| Failed logins, invalid reset/verification tokens | `SECURITY_ALERT_FAILED_LOGIN_THRESHOLD` |
| Refused requests (ordinary 403s) | `SECURITY_ALERT_AUTHZ_THRESHOLD` |
| Refused requests on admin endpoints | 3 |
| 18+ age-gate bypass attempts | 5 |
| Acting on a share addressed to another account | 5 |
| Dropbox token refresh failures per account | 3 |

Crossing a threshold sends **one** message carrying the count ("3 occurrences
were recorded in the last 15 minutes") and starts a cooldown for that event and
source. Everything keeps being logged; only the mail is throttled.

### What never alerts

Ordinary logins and logouts, one failed password, a normal password-reset
request, a verification email, deleting a single comic, a normal import, the
whole normal share lifecycle — including a sender's responsibility
acknowledgement and a recipient's adult confirmation — one expired invitation,
and a single 403 that a stale browser tab can produce.

## Correlation ids

Every request is given an id and returns it as `X-Request-Id`. Security and audit
records carry it as `request_id`, so the records from one request can be found
together, and an alert email quotes it — the message names the day's log file,
and this is what finds the line in it. It is **generated**, never taken from an
inbound header: a correlation id a caller chooses can be reused to make two
unrelated incidents look like one.

Security alert emails direct the operator to the dated security log and its
request id. They link separately to `/admin?tab=audit` for administrator audit
history; an alert is not itself an `admin_audit_log` row.

## Adding a new event

1. Add a constant to `App\Service\SecurityAuditLogger`. Event names are stable
   and dotted: `audit.<subject>.<past-tense-verb>` or
   `security.<subject>.<what-happened>`. Free-form prose is not an event name.
2. Choose the method, not the channel:
   - `audit()` — it succeeded and somebody may need to reconstruct it. Never
     alerts.
   - `security()` — refused or suspicious; logged only.
   - `suspicious()` — refused, and worth telling an administrator if it keeps
     happening. Needs a scope (`user:12`, `ip:…`) and may take a threshold.
   - `critical()` — serious on its first occurrence; logs and alerts at once.
3. Pass identifiers only (see above).
4. Log **after** the flush or commit, so nothing is recorded that a failure could
   still have undone. An audit stream that reports operations which did not
   happen is worse than one that is quiet about an operation that did. Where a
   service runs inside a transaction the caller owns — `tombstoneSharesForComic()`
   is the example — it returns a count instead of logging, and the caller records
   it once the commit has returned.
5. Cover it. The channel routing, redaction and throttling all have tests in
   `backend/tests/Functional/` and `backend/tests/Unit/`; a new event with no
   test is a line nobody will notice stopped being written.
