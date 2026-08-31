# Library covers: request pacing and recovery

A cover is not a static file to the browser's mind but a full application
request. `GET /api/comics/cover/{userId}/{comicId}/{filename}` boots Symfony,
resolves the session, asks the comic voter whether this request may see this
comic, and only then returns the bytes. It is cheap for one comic and expensive
for eighty at once.

Eighty at once is exactly what a library grid used to do. `loading="lazy"`
defers an off-screen image, but it says nothing about how many may start
together, and a scroll through a large library makes a whole screenful eligible
within a frame or two. The browser opens all of them, PHP-FPM runs out of
workers, and the requests it cannot serve come back as errors that the browser
renders as broken images — permanently, because nothing ever asks again. A
moment of load left a lasting hole in the grid.

## The cap

`src/lib/cover-loading.js` hands out a fixed number of tickets
(`COVER_REQUEST_LIMIT`, four) for the whole application. A card asks for one
when it comes on screen and holds it until its image has either decoded or
failed; no `src` is set until it is granted.

The queue is first in, first out, with one property that matters more than the
order: **a card that scrolls out of view releases a ticket it has not yet been
granted**. Fast scrolling therefore drains the queue towards what is on screen
now rather than towards wherever the reader was a second ago. Without that, the
cap would only spread the same doomed burst over a longer period.

Each ticket accepts an `AbortSignal`. Leaving the preload margin abandons a
ticket that is still queued; once a ticket has set an image's `src`, it remains
held until that request settles because native `<img>` requests do not accept
an `AbortSignal`. Replacing or removing a cover URL, or unmounting its card,
returns any ticket that no longer has a consumer. Fetching stays with the
browser so its normal image cache remains in use rather than routing every
cover through fetched blobs and object URLs.

The browser still does the fetching. This decides who may start, not how — so
HTTP caching, decoding and `alt` text on failure all behave normally. Covers are
served `private, immutable` with a long max-age, so returning to the library
re-displays them without asking again at all.

## Recovery

A cover that fails is retried automatically, on two conditions.

**It has to be a different URL.** The first attempt failed at the server, and a
browser holding anything for the original URL will hand that back rather than
ask again. Retry *n* appends `?retry=n`, which the cover endpoint ignores.

**It has to be on screen.** The retry timer only raises the attempt number;
whether that becomes a request is decided by the same on-screen test as the
first attempt. A cover that failed and then scrolled away waits, and asks again
when it is scrolled back to. Retrying for something nobody is looking at is the
original problem wearing a hat.

Backoff doubles from 600 ms to a ceiling of 8 s and is **jittered**, because
covers fail in bursts: a saturated server refuses a screenful together, and a
fixed delay would bring that whole screenful back together too.

The retry lifecycle also has an `AbortController` scoped to the cover URL. A
replacement URL aborts the old lifecycle and clears its timer, while the signal
guard prevents an already-queued callback from resetting a replacement cover
that has since loaded.

After `COVER_MAX_ATTEMPTS` (four, counting the first), the card stops and shows
a Retry button. By then the failure has outlived a burst of load, and a reader
deciding to ask again is better information than a timer deciding it.

## What the reader sees

| State | Shown |
| --- | --- |
| `absent` | A book icon. The comic has no cover; nothing is requested |
| `idle` | A plain panel. Off screen, so nothing is requested and nothing animates |
| `loading` | Comic panels being laid onto a page, with a light sweeping over them |
| `retrying` | The same, with a spinning marker in the corner |
| `loaded` | The cover, faded in over 150 ms |
| `failed` | "Cover unavailable", and a Retry button |

Nothing animates until the card is near the viewport. A large library is
hundreds of these, and a placeholder animating in every one of them spends the
frame budget on cells nobody is looking at — the same mistake as the requests,
in the compositor instead of the network.

The placeholder is comic-shaped rather than a spinner because a grid of forty
cells would otherwise be forty spinners; panels settling into the space the
cover is about to occupy read as a page being drawn rather than as a page
failing. It is `aria-hidden` and animates only under `motion-safe` — the card
already carries the title as text, and announcing "loading" forty times adds
nothing.

The current state is on the cover element as `data-cover-state`, which is also
what the tests assert against.

## Where it applies

`ComicCardCover` — the library grid, which is where the burst came from. The
hook (`src/hooks/use-cover-image.js`) is not specific to it: any list that can
put dozens of covers on screen at once wants the same treatment, and they share
one set of tickets because they share one server. The small fixed lists — a
share invitation, the next-comic button — set `src` directly and are not worth
the ceremony.

The first `EAGER_COVER_COUNT` cards in the grid pass `eager`, which skips
waiting to be scrolled to: they are above the fold and have no scrolling to wait
for. They still take tickets, and being first in the queue they get them.
