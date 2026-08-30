<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AdvertisingConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Safe, read-only production checks for the application side of AdSense. */
#[AsCommand(name: 'app:diagnose-advertising', description: 'Show effective AdSense, Offerwall, CSP and runtime-config state')]
final class DiagnoseAdvertisingCommand extends Command
{
    /**
     * Printed for an operator comparing it against their AdSense page
     * exclusions. A copy of `AD_SAFE_ROUTES` in `frontend/src/lib/advertising.js`,
     * which stays the authority; `advertising.test.js` reads this back and fails
     * on any drift.
     *
     * @var list<string>
     */
    public const AD_SAFE_ROUTES = ['/', '/login', '/upload', '/upload/bulk'];

    public function __construct(
        private readonly AdvertisingConfiguration $advertising,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = self::runtimeConfigMode($this->projectDir);

        $io->title('Advertising diagnostics');
        $io->definitionList(
            ['Effective advertising' => $this->yesNo($this->advertising->isEnabled())],
            ['Publisher id valid' => $this->yesNo($this->advertising->hasValidClient())],
            ['ads.txt expected' => $this->yesNo($this->advertising->isEnabled())],
            ['Runtime config mode' => $mode],
            ['Ad-safe routes' => implode(', ', self::AD_SAFE_ROUTES)],
            ['Rewarded integration' => 'Google AdSense Offerwall (account-side; no application completion callback)'],
            ['Advertising CSP' => 'per-response nonce + strict-dynamic'],
        );

        if (str_starts_with($mode, 'compiled dotenv')) {
            $io->warning('Symfony is reading .env.local.php. Changes to .env.local will not take effect until the compiled file is regenerated or removed.');
        }

        $io->note('Visible ads and Offerwall inventory are Google decisions. Verify script requests, browser CSP reports, consent UI and AdSense account targeting separately.');

        return Command::SUCCESS;
    }

    public static function runtimeConfigMode(string $projectDir): string
    {
        if (is_file($projectDir.'/.env.local.php')) {
            return 'compiled dotenv (.env.local.php)';
        }
        if (is_file($projectDir.'/.env.local')) {
            return 'dotenv (.env.local)';
        }
        if (is_file($projectDir.'/.env.prod.local')) {
            return 'dotenv (.env.prod.local)';
        }

        return 'process environment / base .env';
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
