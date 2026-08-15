# Page derivatives, thumbnails and page geometry

A comic page as its uploader exported it is routinely 2000–6000 pixels wide. A
phone shows it across about 400 CSS pixels. Serving the source to that phone
downloads and decodes twenty times the image it can display, which is the
difference between a comic that reads on a train and one that stalls.

Every page therefore leaves the server through one pipeline that produces a
**bounded set of sizes**, caches what it produces, and remembers the shape of the
pages it has seen. The pipeline knows nothing about CBZ, CBR, CB7, CBT or PDF:
pages arrive from the source provider factory, and everything past that point is
about size, cache and geometry.

## Variants

| Variant | Width | Used for |
| --- | --- | --- |
| `thumb` | 280 | The page navigator |
| `reader-small` | 800 | Phones, narrow windows |
| `reader-medium` | 1400 | The common reading size |
| `reader-large` | 2200 | Large screens, and zoom |
| `original` | source | Whatever the comic stores |

```http
GET /api/comics/{id}/pages/{page}?variant=reader-medium
```

The set is closed. An unrecognised variant is refused with `400` rather than
rounded to the nearest known size — a client and server that disagree about what
exists should say so, not quietly serve something else. A free-form width
parameter would also let one reader mint unlimited cache keys, each costing a
full-size decode.

A variant is a **ceiling, not a target**. A 600-pixel page requested as
`reader-large` is served at 600 pixels: upscaling would spend bytes to deliver
exactly the same detail. Naming no variant at all serves the source page, which
is what clients received before variants existed.

For archives the source entry is read and resized once. For a PDF page that has
to be drawn, the target width is passed to the renderer, so a thumbnail is drawn
at thumbnail size rather than rasterised at 2400 pixels and shrunk afterwards.

## What the reader asks for

The reader measures the room the page actually has, multiplies by the device
pixel ratio (capped at 3), and takes the smallest variant that covers it. Zoom
multiplies the same figure, so zooming moves **one rung up the ladder** rather
than reaching for the source scan.

The reader only ever moves up. Shrinking a window does not drop back to a
smaller page: the bytes are already paid for, and re-downloading to show less is
not an improvement.

## Page geometry

```http
GET /api/comics/{id}/pages?from=1
```

```json
{
  "pageCount": 24,
  "complete": false,
  "variants": { "thumb": 280, "reader-medium": 1400, "original": null },
  "pages": [{ "page": 1, "width": 1988, "height": 3056, "aspectRatio": 0.6505 }]
}
```

Only numbers: archive entry names and filesystem paths are never exposed. The
geometry is the **source page's** own, never the variant that happened to ask
for it, so a wide double-page spread reads as wide whether it was first seen as a
thumbnail or a full page. Pages drawn by a renderer have no source pixels of
their own, so their render dimensions are not recorded as geometry.

Inspecting a whole book at once is too expensive — for a PDF a single page can
cost seconds — so a manifest request measures a few unmeasured pages, within a
time budget, starting from `from`, and reports whether it is `complete`. Serving
pages fills the rest in for free, since a page being read is already open.

Nothing depends on geometry being complete. A reader without it lays pages out
from the images themselves, exactly as it did before this existed.

## The thumbnail navigator

The page navigator is built from the same pipeline at `thumb` size, which means
it inherits the same authorization — a thumbnail is a page. Slots for every page
exist from the start, because they are what gives the strip its scroll length and
the browser its tab order, but images are fetched only near the current page and
for slots actually scrolled to: opening a 400-page book is not 400 requests.

Each slot is a button labelled with its logical page number, and selecting one
goes through the reader's shared `goToPage`. There is no separate navigation
contract for thumbnails.

## Cache and invalidation

Derivatives live under `var/page-cache/{comicId}/`, deliberately outside the web
root: they come from comics whose access is checked on every request, so they
must never be reachable by guessing a URL.

A cache entry is keyed by comic, logical page, variant and a fingerprint of the
source's path, modification time, size and the pipeline's render version.
Consequently:

- replacing a comic's file invalidates every derivative made from the old one;
- deleting a comic drops its derivatives with it;
- editing metadata regenerates nothing — retitling a comic does not change a
  pixel;
- changing variant widths or encoder quality invalidates the world through the
  render version, which is also part of the ETag, so browsers re-ask too.

Writes go to a temporary file and are renamed into place, so a reader can never
be handed a half-written page. Generation is single-flight: a request that finds
another one already producing the same derivative waits for it rather than
producing a second copy, and a hundred simultaneous misses on the same page cost
one resize. A generator that dies holding its lock does not strand anybody — the
waiter gives up after a few seconds and produces the page itself.

Nothing here is authoritative. The whole directory can be deleted at any moment
and the only cost is regenerating what was in it. Every failure in the pipeline —
a GD without WebP, an image too large to decode, a cache directory that cannot be
written — ends in a served page rather than an error.

## Storage and quota

Derivatives are **rebuildable server cache, not user files**. The canonical
source counts towards the uploader's storage quota; nothing generated here does.
The cache has its own size and age policy — see `app:comic-pages:prune`, which
drops derivatives nobody has read since a cutoff, and everything belonging to
comics that no longer exist.

## Authorization

Every variant and every manifest request goes through the same `COMIC_VIEW`
check as the comic's metadata, including the 18+ gate on an explicit share: an
accepted share on a comic marked explicit answers "no" until that recipient has
declared their age. Thumbnails and page geometry are comic content, so a gated
comic reveals neither its page shapes nor a low-resolution version of its
artwork. A comic that cannot be viewed is reported as missing rather than
forbidden, so probing for identifiers learns nothing.
