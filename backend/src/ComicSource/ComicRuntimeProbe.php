<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What this host can actually do with comic sources, asked of the host itself.
 *
 * Separate from ComicFormatService because that one owns what an administrator
 * has chosen, which lives in the database, while this owns what the machine is
 * capable of, which does not. Keeping them apart is what lets the format
 * diagnostic answer "can this server read PDFs" on a server whose database is
 * the broken thing.
 */
final class ComicRuntimeProbe
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * Whether PHP on this host may start a subprocess at all.
     *
     * `function_exists` already reports false for anything in
     * `disable_functions`, which is how shared hosts usually remove it.
     */
    public static function canRunExternalTools(): bool
    {
        return \function_exists('proc_open');
    }

    /**
     * @return array<string, bool> keyed by ComicSourceType value
     */
    public function availability(): array
    {
        // A host that forbids subprocesses can serve CBZ and nothing else.
        // Checking this first also keeps us from constructing a Process, whose
        // constructor throws outright when proc_open is gone — that exception
        // would otherwise escape through ComicFormatService::isEnabled() and
        // take down the upload and configuration endpoints on exactly the
        // shared hosting the FTP deployment guide supports.
        $canShellOut = self::canRunExternalTools();

        $finder = new ExecutableFinder();
        $sevenZip = $canShellOut ? $finder->find('7z') : null;
        $hasRar = $sevenZip !== null && $this->sevenZipReadsRar($sevenZip);

        $availability = [];
        foreach (ComicSourceType::cases() as $type) {
            $availability[$type->value] = match ($type) {
                ComicSourceType::CBZ => class_exists(\ZipArchive::class),
                ComicSourceType::CBR => $hasRar,
                ComicSourceType::CB7, ComicSourceType::CBT => $sevenZip !== null,
                // PDF needs nothing installed for the documents comics actually
                // come as: one full-page image per page, read in pure PHP.
                // Poppler widens that to pages that have to be drawn, and its
                // absence is a limitation rather than an outage.
                ComicSourceType::PDF => \function_exists('gzuncompress'),
            };
        }

        return $availability;
    }

    /**
     * Whether pages that are not a single embedded image can be rendered.
     * PDF works without this; fewer documents do.
     */
    public function hasPoppler(): bool
    {
        if (!self::canRunExternalTools()) return false;

        $finder = new ExecutableFinder();

        return $finder->find('pdfinfo') !== null && $finder->find('pdftocairo') !== null;
    }

    /**
     * Whether this 7z was built with the RAR handler.
     *
     * The binary being present is not the same as CBR working. Several
     * distributions ship the RAR decoder in a separate, often non-free package,
     * so 7z reads 7z and tar perfectly while every CBR fails at inspection.
     * Reporting CBR as available on such a host would let an administrator
     * enable a format their server cannot read, and the failure would surface
     * to users as a broken upload rather than to the admin as a missing package.
     *
     * `7z i` lists the handlers the build registered; RAR appears there only as
     * the Rar and Rar5 handlers.
     */
    private function sevenZipReadsRar(string $sevenZip): bool
    {
        try {
            $process = new Process([$sevenZip, 'i']);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();

            return $process->isSuccessful() && preg_match('/\bRar5?\b/', $process->getOutput()) === 1;
        } catch (\Throwable) {
            // Probing must never be the thing that breaks a page. Anything we
            // cannot ask about, we report as unavailable.
            return false;
        }
    }
}
