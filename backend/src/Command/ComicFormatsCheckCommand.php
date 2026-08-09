<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;

#[AsCommand(name: 'app:comic-formats:check', description: 'Check comic source runtime dependencies')]
final class ComicFormatsCheckCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $finder = new ExecutableFinder();
        $requirements = ['CBZ / PHP ZIP' => class_exists(\ZipArchive::class), 'CBR/CB7/CBT / 7z' => $finder->find('7z') !== null, 'PDF inspection / pdfinfo' => $finder->find('pdfinfo') !== null, 'PDF rendering / pdftocairo' => $finder->find('pdftocairo') !== null];
        foreach ($requirements as $label => $available) $output->writeln(sprintf('%s: %s', $label, $available ? '<info>yes</info>' : '<error>no</error>'));
        return in_array(false, $requirements, true) ? Command::FAILURE : Command::SUCCESS;
    }
}
