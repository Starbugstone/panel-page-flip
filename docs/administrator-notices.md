# Administrator notices

The step between noticing a problem and acting on it.

Before this, an administrator who found a mis-tagged comic or a share that
should not have been made had two options: leave it alone, or take the content
away. Neither tells the account what was wrong, so the same thing happens again
— and somebody whose library quietly changed is left guessing why.

A notice is a message and deliberately only a message.

## What it is not

- **It blocks nothing.** Reading, uploading and sharing are untouched.
  Restricting an account is a separate decision on the
  [content report](content-reporting.md) screen, and conflating the two would
  make every notice feel like a punishment already applied.
- **It is not from a person.** The recipient is never told which administrator
  sent it. A notice is from the operator of the service, and naming an
  individual invites an argument with them.
- **It is not deleted when it is read.** Dismissing marks it acknowledged and
  keeps the row, so "were they told?" has an answer after the second incident.

## Issuing one

The **Warn** button appears in three places, and all three post to the same
endpoint:

| Tab | Button warns | Body carries |
| --- | --- | --- |
| Users | that account | `userId` |
| Comics | the comic's owner | `comicId` |
| Shares | whoever made the share | `shareId` |

`POST /api/admin/warnings` takes exactly one of those three plus a `message`,
and works out the recipient itself. Warning "a comic" means warning whoever owns
it; warning "a share" means warning the account that handed it out, not the
account holding it. Naming two targets is a 400 rather than being resolved in
whichever order the implementation happens to check.

An administrator cannot warn themselves. That is not a rule about who deserves
one — warning another administrator is legitimate — but a notice dismissed by
the person who wrote it is a mistake every time.

## The message

Trimmed, newline-normalised, and at most `UserWarning::MAX_MESSAGE_LENGTH`
(2000) characters. Whitespace-only is empty: a notice with no words in it tells
somebody that something is wrong and nothing about what, which is worse than not
sending one. Line breaks are preserved on the way out — an administrator
explaining a problem uses them, and running them together makes a careful
message read as a shout.

## Context that outlives its subject

A notice about a comic stores the comic *and* its title, and a notice about a
share stores the share, the comic, and the comic's title.

The references are the live link; the label is the durable one. The usual reason
to warn somebody about a comic is that the comic is about to be removed, and a
notice that then reads as a complaint about nothing in particular is no use to
the person receiving it. The foreign keys are `ON DELETE SET NULL` for exactly
this reason — only the recipient's own deletion cascades, because their account
going means there is nobody left to read it.

## Delivery

The in-app notice **is** the delivery. It is written and flushed before anything
is emailed, so a mail server that hangs cannot leave the recipient without it.

Ticking **Also email them a copy** sends the same message to the address on the
account, rendered from `emails/user_warning.html.twig`. That copy is deliberately
not a second, richer channel: it names no administrator, quotes no report, and
links to nothing that would act on the recipient's behalf if the mail were
opened by the wrong person.

A delivered email copy is confirmed in the success response. A failed send is
recorded on the row as `email_state = failed` and reported back to the
administrator; it never raises. The notice is real and waiting either way,
which is the delivery that matters.

## What the recipient sees

`GET /api/me/warnings` returns undismissed notices, oldest first, capped at
`UserWarningRepository::MAX_OPEN_PER_RECIPIENT`. Oldest first because they are
read and dismissed in order, and a newer one jumping the queue would leave the
older one for last — the wrong way round for a sequence of escalating notices.

The banner sits above the routed page rather than on one of them, so a notice
about a comic is readable from wherever the reader happens to be. It is never
dismissed automatically: a banner that disappears on navigation is one somebody
can miss entirely.

`POST /api/me/warnings/{id}/acknowledge` dismisses one. Somebody else's is
reported as missing rather than forbidden, so an id cannot be used to find out
whether an account has been warned.

## Audit and privacy boundaries

`SecurityAuditLogger::USER_WARNING_ISSUED` records identifiers and shape only —
the warning id, the subject, the comic or share id, and whether an email was
asked for. Never the message. The administrator's words to one person live in
the row they were written into, which has its own retention and its own
audience; repeating them into Monolog would put them somewhere else with
neither.

Notices appear in the recipient's personal data export as
`administratorNotices`, dismissed ones included, and without the administrator
who sent them: a notice is stored *about* the subject, but who wrote it is the
operator's record rather than a fact about them.
