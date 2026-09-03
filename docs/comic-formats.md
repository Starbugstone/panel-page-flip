# Comic source formats

Panel Page Flip accepts CBZ (ZIP), CBR (RAR), CB7 (7z), CBT (tar), and PDF as canonical comic sources. All are exposed to the reader through the same protected numbered-page endpoint; original sources are never public.

CBZ and PDF are the two native formats: both are read with no external tooling, so both work on any host the application itself runs on. For PDF that covers image-based documents — one full-page image per page, which is what scanned and exported comics are; a PDF whose pages have to be drawn needs Poppler and is refused at upload without it. The CB* archive formats need `7z` present. Past the source factory nothing downstream — reader, covers, sharing, quotas, deletion — knows or cares which format a comic came from.

New installations enable CBZ only. An administrator must open **Admin → Formats**, verify the installed runtime, select the available optional formats, and save. The public uploader and every backend upload path use this allow-list; a file extension alone never enables a format.

## Runtime requirements

The development Docker image installs PHP ZIP, `7z`, Poppler (`pdfinfo` and `pdftocairo`), and `qpdf`. **Production is a separate server and provides none of that by default** — see [SSH-deploy.md](../SSH-deploy.md) for a VPS or [deploy.md](../deploy.md) for shared hosting.

What every host must provide is the `zip` and `zlib` PHP extensions, which read CBZ and PDF, and a GD built with JPEG and WebP for the page pipeline. Everything else is optional and only widens which formats can be offered.

Run `php bin/console app:comic-formats:check` on the server after deploying. It reports formats, page delivery and what to install, and exits non-zero when an essential format is unserviceable. `qpdf` is a fully optional extra: it adds a structural check on smaller PDFs, and without it PDFs are still accepted on the Poppler checks alone — see [the structural check is size-gated](#the-structural-check-is-size-gated).

The check reports what this host can do, what each format needs, and how to install it. It reads the same availability the **Admin → Formats** screen does, so the two cannot disagree, and it deliberately keeps working when the database does not — the runtime half needs nothing but the filesystem. It exits non-zero only when a format is switched on that the server cannot actually serve.

Two things it reports that are easy to get wrong:

- **`7z` being installed is not the same as CBR working.** Several distributions ship the RAR decoder in a separate, often non-free package (`p7zip-rar`). Without it `7z` reads 7z and tar perfectly and fails on every CBR, so availability is probed from the handlers `7z i` reports rather than from the binary existing.
- **Shared hosting usually forbids subprocesses.** Where `proc_open` is in `disable_functions`, no format needing an external tool can work, and the check says so instead of advising a package install the admin has no way to perform. CBZ and image-based PDF need nothing external and keep working there.

## PDF

PDF is a first-class source alongside CBZ, and like CBZ it needs nothing installed for the documents comics actually come as.

A scanned or exported comic PDF is a container holding one full-page image per page — the same thing a CBZ is, with a different wrapper. Those pages are read natively, in pure PHP: the source provider returns the page's own embedded image without rasterising it, exactly as the CBZ provider returns an entry from the archive. No subprocess, no renderer, and no intermediate re-encode. This is what lets PDF work on shared hosting, where `proc_open` is usually disabled and no package can be installed.

Native parsing is capped at 500 MiB per document and also bounds container values, names, and strings before allocating them. The reader checks the opened file's size first and allocates only that size, so a small PDF does not reserve the entire ceiling and an oversized one is rejected without being read. Larger PDFs take the Poppler path when it is available; a host without Poppler rejects them cleanly at upload.

What reaches the browser is then whatever **Page delivery** below produces from those bytes, normally WebP — reading a page natively is about not needing a renderer, not about the response being the embedded file.

Poppler extends that to the documents the native reader cannot serve — pages built from vector art or text, which have no embedded image to hand over. Where Poppler is present those pages are rendered lazily, one requested page at a time, to a maximum 2400-pixel reader image. Where it is absent such a document is refused at upload with a clear message, rather than importing and then failing at page three.

Encrypted and password-protected PDFs are rejected either way, and are told apart from merely damaged ones: the page-count check decides that before the structural check runs, so a password-protected comic is reported as encrypted rather than as damaged.

### The structural check is size-gated

`qpdf --check` reads and validates every object in a document, so what it costs is linear in **file size** — about 0.4 seconds per megabyte on the development image. Everything else in the acceptance path is flat: `pdfinfo` answers in about 0.05s and rendering page one in about 0.15s, whatever the document weighs.

That asymmetry matters because comic PDFs are large. A 120 MB manga volume needs roughly 45 seconds of that check and a 500 MB one nearly three minutes — all of it inside the request the uploader is waiting on, holding a PHP-FPM worker, to obtain a *second opinion* on a document the very next step proves servable by rendering a page of it.

So the check runs only on documents it can finish. Two settings decide which:

| Setting | Default | What it is |
| --- | --- | --- |
| `PDF_STRUCTURE_CHECK_BUDGET_SECONDS` | `8.0` | What the check may cost per document. `0` disables it. |
| `PDF_STRUCTURE_CHECK_SECONDS_PER_MEGABYTE` | `0.4` | What it costs per megabyte **on your host**. |

Budget divided by rate is the largest document that gets checked — `8.0 / 0.4` is 20 MB by default, the single issues where a silent structural fault is most likely to go unnoticed. Anything larger skips the check deliberately and imports on the Poppler checks alone, which is exactly what a host without `qpdf` already does.

**The rate is worth correcting.** It is a property of the hardware rather than of this application — a slower disk or a busier CPU changes it — and the default was measured on a development container, not on anybody's server. To measure your own, time the check on a large PDF:

```bash
time qpdf --check --no-warn /path/to/a-large-comic.pdf
```

Divide the seconds by the file's size in megabytes and set `PDF_STRUCTURE_CHECK_SECONDS_PER_MEGABYTE` to the result. `php bin/console app:comic-formats:check` prints the threshold your current settings produce, so you can see what changed:

```text
PDF structural check / qpdf (optional): yes
 Checks PDFs up to 20 MB (8.0s budget at 0.40s/MB); larger ones import on the Poppler checks alone.
```

Setting the budget to `0` turns the check off everywhere. That is a supported configuration rather than a degraded one; raise the budget if your host is fast and your library is mostly small documents.

A check that does start and then runs out of time is **not** treated as a failed document. A second opinion that did not arrive is not a negative one, and the alternative — which this project shipped until it was measured — rejects precisely the large, legitimate files that are slowest to check.

A PDF's page count is cached exactly as a CBZ's page index is, keyed by path, modification time and size, so turning pages does not re-parse the whole document each time.

## Page delivery

Whatever a comic was stored as, a page normally leaves the server as WebP. Normally rather than always: where conversion is not possible the provider's own bytes are served instead, and the `Content-Type` says which happened.

The source providers hand back whatever the page happens to be — a JPEG out of a CBZ, a PNG repacked from a PDF bitmap, a rendered page from Poppler — which would otherwise make a reader's bandwidth depend on how the uploader happened to export their comic. Each page is converted once, at the size that was asked for, cached, and served from the cache afterwards.

A page is also delivered in one of a fixed set of sizes rather than at whatever dimensions the uploader exported, so a phone does not download a 4000-pixel scan to fill 400 CSS pixels. A PDF page that has to be drawn is drawn near the requested size rather than rasterised at full resolution and shrunk. The variants, the page-geometry manifest, the thumbnail navigator and the cache's invalidation rules are described in [page-derivatives.md](page-derivatives.md).

Generated pages live under `var/page-cache/{comicId}/`, deliberately outside the web root: they are derived from comics whose access is checked on every request, so they must never be reachable by guessing a URL. The cache holds nothing authoritative and can be deleted at any time; entries are keyed by the source's modification time and size, so replacing a comic's file cannot serve pages from the previous one. Deleting a comic drops its pages with it.

Every failure in this path ends in a served page rather than an error. A GD without WebP, an image it cannot decode, a page too large to convert, a cache directory that cannot be written — each falls back to serving exactly what the provider produced. The `Content-Type` and the ETag both reflect the format actually used, so a server that gains or loses its WebP encoder does not leave browsers revalidating stale bytes as current.

## Essential and optional

CBZ and PDF are **essential**: neither needs anything installed, so both are expected to work on any host the application runs on. If either is unavailable, **Admin → Formats** shows a prominent alert and `app:comic-formats:check` exits non-zero — that state is a broken installation rather than an administrator's choice.

CBR, CB7, CBT, Poppler and qpdf are **optional**. Their absence is reported, never alerted: the application falls back to the two native formats and keeps working.

Rendering has a 30-second timeout and uses a random, mode-0700 temporary directory that is removed after every attempt. At most three renders run concurrently per application lock store; beyond that a request waits up to 20 seconds for a free slot before reporting the renderer as busy, so a reader that fetches the current page and prefetches the next one is never refused for it.

Archive inputs are limited to 10,000 entries, 2 GiB total reported uncompressed data, 64 MiB per page, and a 100:1 maximum expansion ratio between the source archive and its reported contents. External-tool listings stop at 16 MiB, and page paths stop at 1,024 bytes or 16 segments. Only JPG, PNG, GIF, and WebP entries with safe, non-traversing names and matching image content become readable pages. Page names are natural-sorted and never returned by the API.

Direct uploads, chunked uploads, Dropbox import, and `app:import-comics` all use the same enabled-format and provider validation pipeline. A configured format whose runtime later disappears is omitted from uploader configuration and rejected until the runtime is restored or the format is disabled.

The direct multipart endpoint validates its form before admitting the file:
title and optional metadata must be strings, tags must be a JSON list of
strings, and a destination must be a positive owned-folder identifier. Bad
form data is a 400 response and never reaches storage or format inspection.

Upload size and per-user storage quota continue to apply to the original canonical source. Generated pages, including rendered PDF ones, are rebuildable server cache and count towards nobody's quota.

A chunked upload ID identifies one active staging area and cannot be initialized a second time while that upload exists. Chunk indices must be canonical unsigned decimal strings; malformed values are rejected instead of being coerced to chunk zero.
