# Content reports and administrative restrictions

Panel Page Flip provides a narrow notice-and-action workflow for specific allegedly illegal material. It is not a public moderation system and does not scan private libraries proactively.

The public `/report-content` page accepts copyright/IP and other illegal-content notices without requiring an account or an internal comic ID. It asks for reporter contact details, a reference that can locate the material, a substantive explanation, and a good-faith confirmation. A hidden honeypot and the `content_report` rate limiter protect the endpoint. Public responses are deliberately generic and never confirm whether a private user, comic, share, or invitation exists.

The fields follow the elements described by Article 16 of Regulation (EU) 2022/2065: an adequately substantiated explanation, a location or other identifying information, reporter name/email, and a bona-fide accuracy statement. The implementation is operational support, not a legal-compliance certification. Operators should review the current law and their own obligations before production use:

- [Digital Services Act, Regulation (EU) 2022/2065](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32022R2065), particularly Articles 16 and 17.
- [French Law No. 2004-575 on confidence in the digital economy](https://www.legifrance.gouv.fr/loda/id/JORFTEXT000000801164).

## Configuration

`LEGAL_EMAIL` is the public operational legal contact and reply-to address. It falls back to `PRIVACY_EMAIL`, then `MAILER_FROM_ADDRESS`; do not commit a personal address.

`CONTENT_REPORT_RETENTION_DAYS` defaults to 730 days. Closed and rejected reports older than that are eligible for deletion. Open cases and cases placed on legal hold are never selected by ordinary cleanup.

Run the bounded cleanup from the same scheduler as the existing retention jobs:

```bash
php bin/console app:cleanup-content-reports
```

## Review workflow

Administrators use the **Content reports** tab. A report can be linked to a user, comic, or share after investigation; those links are nullable because a public complainant may only know an external reference.

The intentionally small status set is `received`, `under_review`, `awaiting_information`, `action_taken`, `rejected`, and `closed`. Available actions are:

- restrict or restore sharing for one comic;
- revoke all current shares for a comic;
- logically quarantine or restore a comic;
- restrict or restore an account's ability to share.

A sharing restriction leaves the owner's private reading access intact, blocks new invitations and claim codes, prevents pending acceptance, and removes accepted recipient access from every endpoint guarded by `ComicVoter`. Logical quarantine additionally blocks the owner while retaining administrator access. Restrictions are reversible; lifting one does not recreate deleted or revoked invitations.

Owner notifications carry the report reference and action only. They never include reporter contact details or the full allegation. Reporter acknowledgements similarly carry the reference without echoing the allegation.

## Audit and privacy boundaries

The `ContentReport` database row is the durable case record. Security/audit events contain identifiers, status, category, and action outcomes only. Do not add the allegation, reporter email, invitation tokens, URLs containing secrets, or comic metadata to Monolog.

New-report alerts reuse `SecurityAlertService` and its configured recipient/cooldown mechanism. A logging, cache, or mail failure never rolls back or loses a submitted report.
