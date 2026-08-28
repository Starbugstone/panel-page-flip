# Content reports and administrative restrictions

Panel Page Flip provides a narrow notice-and-action workflow for specific allegedly illegal material. It is not a public moderation system and does not scan private libraries proactively.

The public `/report-content` page accepts copyright/IP and other illegal-content notices without requiring an account or any internal database ID. Reporters classify the locator they actually have: an invitation URL, a C-/G- content code, a U- user code, an account reference, comic/publication metadata, a `/read/{id}` URL, or other evidence. Optional title, account and source-context fields help distinguish search results. A hidden honeypot and the `content_report` rate limiter protect the endpoint. Public responses are deliberately generic and never confirm whether a private user, comic, share, code or invitation exists.

The server parses application URLs locally and never fetches a reporter-supplied URL. Exact application-issued locators are resolved privately: invitation links identify their share, C- codes identify one comic, U- codes identify one account and `/read/{id}` identifies one comic. A G- code and text-like references produce bounded administrator-only candidates and never choose a target automatically.

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

Administrators use the **Content reports** tab. The queue carries summary fields only; reporter contact details, locators and allegations are fetched from the detail endpoint when **Review** is opened. Exact resolution and bounded title/account search produce human-readable candidates. Numeric IDs are diagnostic details after a candidate is shown, not something the administrator has to discover and type.

Target links are canonical. A share sets its comic and owner, a comic sets its owner and clears an incompatible share, and a user cannot contradict the owner implied by a comic/share. Restriction actions validate that invariant again before changing any account or content.

The intentionally small status set is `received`, `under_review`, `awaiting_information`, `action_taken`, `rejected`, and `closed`. Available actions are:

- restrict or restore sharing for one comic;
- revoke all current shares for a comic;
- logically quarantine or restore a comic;
- restrict or restore an account's ability to share.

A sharing restriction leaves the owner's private reading access intact, blocks new invitations and claim codes, prevents pending acceptance, and removes accepted recipient access from every endpoint guarded by `ComicVoter`. Logical quarantine additionally blocks the owner while retaining administrator access. Restrictions are reversible; lifting one does not recreate deleted or revoked invitations.

Owner notifications carry the report reference and action only. They never include reporter contact details or the full allegation. Reporter acknowledgements provide a receipt of the submitted notice, but mask invitation tokens and C-/G-/U- codes so mail does not become another capability-distribution channel. The dedicated operator notice goes only to `LEGAL_EMAIL`, includes the full case material, and deep-links to the private review screen. It does not weaken the field allow-list used by generic security alerts.

## Audit and privacy boundaries

The `ContentReport` database row is the durable case record. Whenever a target is linked, minimal user/comic/share IDs and the comic title are copied into snapshot fields. Normal account/content/share deletion remains allowed—including while a case is on legal hold—to avoid silently changing the account-deletion policy. The live foreign keys may become null, while the minimal snapshot continues to correlate the retained case with the record that was reviewed. Legal hold preserves the case row from report cleanup; it does not block GDPR/account deletion.

Security/audit events contain identifiers, status, category, before/after target IDs, resolution method and action outcomes only. Do not add the allegation, reporter email, invitation tokens, URLs containing secrets, or comic metadata to Monolog. Operator and reporter mail is best effort after the durable insert; a rendering or transport failure never rolls back the report or makes the public POST retry.
