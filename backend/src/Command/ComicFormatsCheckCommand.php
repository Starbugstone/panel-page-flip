<?php

namespace App\Command;

use App\ComicSource\ComicRuntimeProbe;
use App\Service\ComicFormatService;
use App\Service\ComicPageDelivery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;

/**
 * What this server can actually do with comic sources.
 *
 * It reads the same ComicFormatService the admin panel does, deliberately: two
 * separate implementations of "is this runtime here" is how a CLI check ends up
 * disagreeing with the screen an administrator is looking at.
 */
#[AsCommand(name: 'app:comic-formats:check', description: 'Check comic source runtime dependencies')]
final class ComicFormatsCheckCommand extends Command
{
    public function __construct(
        private readonly ComicFormatService $formats,
        private readonly ComicPageDelivery $delivery,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Always probe fresh: somebody running this has usually just changed
        // something about the server and wants to know whether it took.
        $report = $this->formats->runtimeReport(true);

        // Which formats are switched on lives in the database, and this command
        // has to stay useful when that is exactly what is broken. The runtime
        // half above needs nothing but the filesystem.
        $enabled = null;
        try {
            $enabled = array_map(static fn (array $detail): bool => $detail['enabled'], $this->formats->status());
        } catch (\Throwable $exception) {
            $io->warning(sprintf(
                'Could not read which formats are switched on (%s). Runtime availability below is still accurate.',
                $exception->getMessage()
            ));
        }

        $rows = [];
        $missing = [];
        $notes = [];
        foreach ($report as $format => $detail) {
            $rows[] = [
                strtoupper($format),
                $detail['available'] ? '<info>yes</info>' : '<error>no</error>',
                $enabled === null ? '<comment>unknown</comment>' : ($enabled[$format] ? 'yes' : 'no'),
                implode(' + ', $detail['requirements']),
            ];

            if (!$detail['available']) $missing[$format] = $detail['hint'];
            if ($detail['available'] && $detail['note'] !== '') $notes[$format] = $detail['note'];
        }

        $io->table(['Format', 'Available', 'Enabled', 'Requires'], $rows);

        // A format that works but could do more. Reported apart from the
        // failures, because nothing here is broken.
        foreach ($notes as $format => $note) {
            $io->writeln(sprintf(' <comment>%s</comment>: %s', strtoupper($format), $note));
        }
        if ($notes !== []) $io->newLine();

        // Optional, and reported separately so its absence never reads as a
        // format being broken: qpdf is a second opinion on an uploaded PDF.
        // Finding the binary is not enough — without proc_open we could never
        // run it, so report what would actually happen.
        $qpdfUsable = ComicRuntimeProbe::canRunExternalTools() && (new ExecutableFinder())->find('qpdf') !== null;
        $io->writeln(sprintf(
            'PDF structural check / qpdf (optional): %s',
            $qpdfUsable ? '<info>yes</info>' : '<comment>no</comment>'
        ));

        // How pages leave the server is the same question for every comic,
        // independent of which formats are switched on, so it is reported even
        // when every format is healthy.
        $delivery = $this->delivery->describe();
        $io->writeln(sprintf(
            'Page delivery: %s',
            $delivery['healthy'] ? '<info>WebP, cached</info>' : '<comment>source format, uncached</comment>'
        ));
        if ($delivery['hint'] !== '') $io->writeln(' <comment>'.$delivery['hint'].'</comment>');
        $io->newLine();

        // An essential format failing is a broken installation, not a choice.
        // It is reported before anything optional and it fails the command
        // whether or not an administrator has switched the format on.
        $brokenEssentials = array_keys(array_filter(
            $report,
            static fn (array $detail): bool => $detail['essential'] && !$detail['available']
        ));

        if ($brokenEssentials !== []) {
            $io->error(sprintf(
                'This installation is broken: %s cannot be served, and %s meant to work on any host without extra software.',
                implode(' and ', array_map('strtoupper', $brokenEssentials)),
                count($brokenEssentials) === 1 ? 'it is' : 'they are'
            ));
            foreach ($brokenEssentials as $format) {
                $io->writeln(sprintf(' <comment>%s</comment>: %s', strtoupper($format), $report[$format]['hint']));
            }

            return Command::FAILURE;
        }

        if ($missing === []) {
            $io->success('Every supported comic format can be served by this server.');
            return Command::SUCCESS;
        }

        $io->section('To enable the unavailable formats');
        foreach ($missing as $format => $hint) {
            $io->writeln(sprintf(' <comment>%s</comment>: %s', strtoupper($format), $hint));
        }
        $io->newLine();

        // A format that is enabled but unserviceable is the state worth failing
        // on: uploads are being accepted for something this server cannot read.
        $brokenEnabled = $enabled === null ? [] : array_keys(array_filter(
            $report,
            static fn (array $detail, string $format): bool => ($enabled[$format] ?? false) && !$detail['available'],
            ARRAY_FILTER_USE_BOTH
        ));

        if ($brokenEnabled !== []) {
            $io->error(sprintf(
                'Enabled but unusable on this server: %s. Install the tools above or turn these off in Admin → Formats.',
                implode(', ', array_map('strtoupper', $brokenEnabled))
            ));
            return Command::FAILURE;
        }

        $io->note(ComicRuntimeProbe::canRunExternalTools()
            ? 'The unavailable formats above are switched off, so nothing is broken. Install their tools if you want to offer them.'
            : 'The unavailable formats above are switched off, so nothing is broken. This host cannot run external programs, which leaves CBZ and image-based PDF — the two formats that need nothing installed.');
        return Command::SUCCESS;
    }
}
