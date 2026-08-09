# Comic source formats

Panel Page Flip accepts CBZ (ZIP), CBR (RAR), CB7 (7z), CBT (tar), and PDF as canonical comic sources. All are exposed to the reader through the same protected numbered-page endpoint; original sources are never public.

New installations enable CBZ only. An administrator must open **Admin → Formats**, verify the installed runtime, select the available optional formats, and save. The public uploader and every backend upload path use this allow-list; a file extension alone never enables a format.

## Runtime requirements

The PHP image installs PHP ZIP, `7z`, Poppler (`pdfinfo` and `pdftocairo`), and `qpdf`. Run `php bin/console app:comic-formats:check` after deployment. A missing mandatory tool disables processing for its formats with a controlled upload/read error.

PDFs are inspected with Poppler and rendered lazily, one requested page at a time, to a maximum 2400-pixel reader image. Encrypted/password-protected and malformed PDFs are rejected. Rendering has a 30-second timeout, is limited to one active render per application lock store, and uses a random, mode-0700 temporary directory that is removed after every attempt.

Archive inputs are limited to 10,000 entries, 2 GiB total reported uncompressed data, and 64 MiB per page. Only JPG, PNG, GIF, and WebP entries with safe, non-traversing names and matching image content become readable pages. Page names are natural-sorted and never returned by the API.

Direct uploads, chunked uploads, Dropbox sync, and `app:import-comics` all use the same enabled-format and provider validation pipeline. A configured format whose runtime later disappears is omitted from uploader configuration and rejected until the runtime is restored or the format is disabled.

Upload size and per-user storage quota continue to apply to the original canonical source. Generated PDF pages are temporary until the derivative cache pipeline is introduced.
