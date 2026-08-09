# Comic source formats

Panel Page Flip accepts CBZ (ZIP), CBR (RAR), CB7 (7z), CBT (tar), and PDF as canonical comic sources. All are exposed to the reader through the same protected numbered-page endpoint; original sources are never public.

New installations enable CBZ only. An administrator must open **Admin → Formats**, verify the installed runtime, select the available optional formats, and save. The public uploader and every backend upload path use this allow-list; a file extension alone never enables a format.

## Runtime requirements

The PHP image installs PHP ZIP, `7z`, Poppler (`pdfinfo` and `pdftocairo`), and `qpdf`. Run `php bin/console app:comic-formats:check` after deployment. A missing mandatory tool disables processing for its formats with a controlled upload/read error. `qpdf` is the one optional entry: it adds a structural check on upload, and without it PDFs are still accepted on the Poppler checks alone.

The check reports what this host can do, what each format needs, and how to install it. It reads the same availability the **Admin → Formats** screen does, so the two cannot disagree, and it deliberately keeps working when the database does not — the runtime half needs nothing but the filesystem. It exits non-zero only when a format is switched on that the server cannot actually serve.

Two things it reports that are easy to get wrong:

- **`7z` being installed is not the same as CBR working.** Several distributions ship the RAR decoder in a separate, often non-free package (`p7zip-rar`). Without it `7z` reads 7z and tar perfectly and fails on every CBR, so availability is probed from the handlers `7z i` reports rather than from the binary existing.
- **Shared hosting usually forbids subprocesses.** Where `proc_open` is in `disable_functions`, no format needing an external tool can work, and the check says so instead of advising a package install the admin has no way to perform. CBZ needs nothing external and keeps working there.

PDFs are inspected with Poppler and rendered lazily, one requested page at a time, to a maximum 2400-pixel reader image. Encrypted/password-protected and malformed PDFs are rejected, and the two are told apart: Poppler decides that before the structural check runs, so a password-protected comic is reported as encrypted rather than as damaged. The structural check runs once, when the source is imported, and never on a page turn.

A PDF's page count is cached exactly as a CBZ's page index is, keyed by path, modification time and size, so turning pages does not re-run `pdfinfo` against the whole document each time.

Rendering has a 30-second timeout and uses a random, mode-0700 temporary directory that is removed after every attempt. At most three renders run concurrently per application lock store; beyond that a request waits up to 20 seconds for a free slot before reporting the renderer as busy, so a reader that fetches the current page and prefetches the next one is never refused for it.

Archive inputs are limited to 10,000 entries, 2 GiB total reported uncompressed data, and 64 MiB per page. Only JPG, PNG, GIF, and WebP entries with safe, non-traversing names and matching image content become readable pages. Page names are natural-sorted and never returned by the API.

Direct uploads, chunked uploads, Dropbox sync, and `app:import-comics` all use the same enabled-format and provider validation pipeline. A configured format whose runtime later disappears is omitted from uploader configuration and rejected until the runtime is restored or the format is disabled.

Upload size and per-user storage quota continue to apply to the original canonical source. Generated PDF pages are temporary until the derivative cache pipeline is introduced.
