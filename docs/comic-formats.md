# Comic source formats

Panel Page Flip accepts CBZ (ZIP), CBR (RAR), CB7 (7z), CBT (tar), and PDF as canonical comic sources. All are exposed to the reader through the same protected numbered-page endpoint; original sources are never public.

CBZ and PDF are the two native formats: both are read with no external tooling, so both work on any host the application itself runs on. For PDF that covers image-based documents — one full-page image per page, which is what scanned and exported comics are; a PDF whose pages have to be drawn needs Poppler and is refused at upload without it. The CB* archive formats need `7z` present. Past the source factory nothing downstream — reader, covers, sharing, quotas, deletion — knows or cares which format a comic came from.

New installations enable CBZ only. An administrator must open **Admin → Formats**, verify the installed runtime, select the available optional formats, and save. The public uploader and every backend upload path use this allow-list; a file extension alone never enables a format.

## Runtime requirements

The development Docker image installs PHP ZIP, `7z`, Poppler (`pdfinfo` and `pdftocairo`), and `qpdf`. **Production is a separate server and provides none of that by default** — see [SSH-deploy.md](../SSH-deploy.md) for a VPS or [deploy.md](../deploy.md) for shared hosting.

What every host must provide is the `zip` and `zlib` PHP extensions, which read CBZ and PDF, and a GD built with JPEG and WebP for the page pipeline. Everything else is optional and only widens which formats can be offered.

Run `php bin/console app:comic-formats:check` on the server after deploying. It reports formats, page delivery and what to install, and exits non-zero when an essential format is unserviceable. `qpdf` is a fully optional extra: it adds a structural check on upload, and without it PDFs are still accepted on the Poppler checks alone.

The check reports what this host can do, what each format needs, and how to install it. It reads the same availability the **Admin → Formats** screen does, so the two cannot disagree, and it deliberately keeps working when the database does not — the runtime half needs nothing but the filesystem. It exits non-zero only when a format is switched on that the server cannot actually serve.

Two things it reports that are easy to get wrong:

- **`7z` being installed is not the same as CBR working.** Several distributions ship the RAR decoder in a separate, often non-free package (`p7zip-rar`). Without it `7z` reads 7z and tar perfectly and fails on every CBR, so availability is probed from the handlers `7z i` reports rather than from the binary existing.
- **Shared hosting usually forbids subprocesses.** Where `proc_open` is in `disable_functions`, no format needing an external tool can work, and the check says so instead of advising a package install the admin has no way to perform. CBZ and image-based PDF need nothing external and keep working there.

## PDF

PDF is a first-class source alongside CBZ, and like CBZ it needs nothing installed for the documents comics actually come as.

A scanned or exported comic PDF is a container holding one full-page image per page — the same thing a CBZ is, with a different wrapper. Those pages are read natively, in pure PHP: the source provider returns the page's own embedded image without rasterising it, exactly as the CBZ provider returns an entry from the archive. No subprocess, no renderer, and no intermediate re-encode. This is what lets PDF work on shared hosting, where `proc_open` is usually disabled and no package can be installed.

What reaches the browser is then whatever **Page delivery** below produces from those bytes, normally WebP — reading a page natively is about not needing a renderer, not about the response being the embedded file.

Poppler extends that to the documents the native reader cannot serve — pages built from vector art or text, which have no embedded image to hand over. Where Poppler is present those pages are rendered lazily, one requested page at a time, to a maximum 2400-pixel reader image. Where it is absent such a document is refused at upload with a clear message, rather than importing and then failing at page three.

Encrypted and password-protected PDFs are rejected either way, and are told apart from merely damaged ones: the page-count check decides that before the structural check runs, so a password-protected comic is reported as encrypted rather than as damaged.

A PDF's page count is cached exactly as a CBZ's page index is, keyed by path, modification time and size, so turning pages does not re-parse the whole document each time.

## Page delivery

Whatever a comic was stored as, a page normally leaves the server as WebP. Normally rather than always: where conversion is not possible the provider's own bytes are served instead, and the `Content-Type` says which happened.

The source providers hand back whatever the page happens to be — a JPEG out of a CBZ, a PNG repacked from a PDF bitmap, a rendered page from Poppler — which would otherwise make a reader's bandwidth depend on how the uploader happened to export their comic. Each page is converted once, cached, and served from the cache afterwards.

Generated pages live under `var/page-cache/{comicId}/`, deliberately outside the web root: they are derived from comics whose access is checked on every request, so they must never be reachable by guessing a URL. The cache holds nothing authoritative and can be deleted at any time; entries are keyed by the source's modification time and size, so replacing a comic's file cannot serve pages from the previous one. Deleting a comic drops its pages with it.

Every failure in this path ends in a served page rather than an error. A GD without WebP, an image it cannot decode, a page too large to convert, a cache directory that cannot be written — each falls back to serving exactly what the provider produced. The `Content-Type` and the ETag both reflect the format actually used, so a server that gains or loses its WebP encoder does not leave browsers revalidating stale bytes as current.

## Essential and optional

CBZ and PDF are **essential**: neither needs anything installed, so both are expected to work on any host the application runs on. If either is unavailable, **Admin → Formats** shows a prominent alert and `app:comic-formats:check` exits non-zero — that state is a broken installation rather than an administrator's choice.

CBR, CB7, CBT, Poppler and qpdf are **optional**. Their absence is reported, never alerted: the application falls back to the two native formats and keeps working.

Rendering has a 30-second timeout and uses a random, mode-0700 temporary directory that is removed after every attempt. At most three renders run concurrently per application lock store; beyond that a request waits up to 20 seconds for a free slot before reporting the renderer as busy, so a reader that fetches the current page and prefetches the next one is never refused for it.

Archive inputs are limited to 10,000 entries, 2 GiB total reported uncompressed data, and 64 MiB per page. Only JPG, PNG, GIF, and WebP entries with safe, non-traversing names and matching image content become readable pages. Page names are natural-sorted and never returned by the API.

Direct uploads, chunked uploads, Dropbox sync, and `app:import-comics` all use the same enabled-format and provider validation pipeline. A configured format whose runtime later disappears is omitted from uploader configuration and rejected until the runtime is restored or the format is disabled.

Upload size and per-user storage quota continue to apply to the original canonical source. Generated PDF pages are temporary until the derivative cache pipeline is introduced.
