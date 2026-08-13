<?php

namespace App\Command;

use App\Repository\ComicRepository;
use App\Service\ComicPageCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps the generated page cache from growing without limit.
 *
 * Nothing here is authoritative: every file this deletes can be regenerated
 * from the comic it came from. That is what makes pruning safe to run on a
 * schedule, and why a server short of disk should run it rather than turning
 * the cache off.
 *
 * A library that is read regularly keeps its pages, because reading one
 * refreshes it. Pages nobody has opened in a month are the ones that go.
 */
#[AsCommand(
    name: 'app:comic-pages:prune',
    description: 'Delete generated comic pages that have not been read recently, and pages belonging to comics that no longer exist.',
)]
final class PruneComicPagesCommand extends Command
{
    private const DEFAULT_MAX_AGE_DAYS = 30;

    public function __construct(
        private readonly ComicPageCache $cache,
        private readonly ComicRepository $comics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-age-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete cached pages untouched for this many days. Use 0 to keep every page and only drop orphans.',
                (string) self::DEFAULT_MAX_AGE_DAYS
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $maxAgeDays = (int) $input->getOption('max-age-days');
        if ($maxAgeDays < 0) {
            $io->error('--max-age-days cannot be negative.');
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        // Comics that still exist, so everything else in the cache is an orphan
        // left by a deletion that happened outside the application.
        $known = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->comics->createQueryBuilder('c')->select('c.id')->getQuery()->getArrayResult()
        );

        $result = $this->cache->prune(
            $maxAgeDays === 0 ? null : new \DateTimeImmutable(sprintf('-%d days', $maxAgeDays)),
            array_flip($known),
            $dryRun
        );

        $io->definitionList(
            ['Stale pages' => (string) $result['stale']],
            ['Orphaned comics' => (string) $result['orphans']],
            ['Reclaimed' => self::humanBytes($result['bytes'])],
        );

        if ($dryRun) {
            $io->note('Dry run: nothing was deleted.');
            return Command::SUCCESS;
        }

        $io->success('Comic page cache pruned. Anything removed is regenerated the next time that page is read.');

        return Command::SUCCESS;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
