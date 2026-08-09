# Comic source formats

Panel Page Flip accepts CBZ (ZIP), CBR (RAR), CB7 (7z), CBT (tar), and PDF as canonical comic sources. All are exposed to the reader through the same protected numbered-page endpoint; original sources are never public.

New installations enable CBZ only. An administrator must open **Admin → Formats**, verify the installed runtime, select the available optional formats, and save. The public uploader and every backend upload path use this allow-list; a file extension alone never enables a format.

## Runtime requirements

The PHP image installs PHP ZIP, `7z`, Poppler (`pdfinfo` and `pdftocairo`), and `qpdf`. Run `php bin/console app:comic-formats:check` after deployment. A missing mandatory tool disables processing for its formats with a controlled upload/read error. `qpdf` is the one optional entry: it adds a structural check on upload, and without it PDFs are still accepted on the Poppler checks alone.

PDFs are inspected with Poppler and rendered lazily, one requested page at a time, to a maximum 2400-pixel reader image. Encrypted/password-protected and malformed PDFs are rejected. The structural check runs once, when the source is imported, and never on a page turn.

Rendering has a 30-second timeout and uses a random, mode-0700 temporary directory that is removed after every attempt. At most three renders run concurrently per application lock store; beyond that a request waits up to 20 seconds for a free slot before reporting the renderer as busy, so a reader that fetches the current page and prefetches the next one is never refused for it.

Archive inputs are limited to 10,000 entries, 2 GiB total reported uncompressed data, and 64 MiB per page. Only JPG, PNG, GIF, and WebP entries with safe, non-traversing names and matching image content become readable pages. Page names are natural-sorted and never returned by the API.

Direct uploads, chunked uploads, Dropbox sync, and `app:import-comics` all use the same enabled-format and provider validation pipeline. A configured format whose runtime later disappears is omitted from uploader configuration and rejected until the runtime is restored or the format is disabled.

Upload size and per-user storage quota continue to apply to the original canonical source. Generated PDF pages are temporary until the derivative cache pipeline is introduced.
