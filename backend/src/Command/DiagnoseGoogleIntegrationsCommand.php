<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AdvertisingConfiguration;
use App\Service\ConsentConfiguration;
use App\Service\ContentSecurityPolicy;
use App\Service\FrontendRouteRegistry;
use App\Service\GoogleAnalyticsConfiguration;
use App\Service\PublicUrl;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Safe, read-only production checks for the application side of AdSense and GA4.
 *
 * Both integrations in one command because the questions an operator actually
 * has span them — "why is Analytics off when I enabled it", "which of these owns
 * the consent dialogue" — and two commands would each answer half of that while
 * drifting apart on the shared parts.
 *
 * Deliberately server-observable only. It reports configuration this process can
 * see and the policy it would serve; it does not claim to know what the browser
 * ends up loading, because the tags are injected by React after hydration and a
 * hostname grep over the server's HTML would pass while a real regression sat in
 * a provider. That layer is covered by the frontend loader tests and a
 * real-browser network check — see docs/analytics.md.
 *
 * Nothing here prints a consent value, a cookie, a user identifier or a secret.
 */
#[AsCommand(
    name: 'app:diagnose-google-integrations',
    description: 'Show effective AdSense, Analytics, consent-provider, CSP and runtime-config state',
    aliases: ['app:diagnose-advertising'],
)]
final class DiagnoseGoogleIntegrationsCommand extends Command
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
        private readonly GoogleAnalyticsConfiguration $analytics,
        private readonly ConsentConfiguration $consent,
        private readonly ContentSecurityPolicy $contentSecurityPolicy,
        private readonly FrontendRouteRegistry $routes,
        private readonly PublicUrl $publicUrl,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mode = self::runtimeConfigMode($this->projectDir);
        $googleFree = $this->routes->googleFreeRoutes();

        $io->title('Google integration diagnostics');
        $io->definitionList(
            ['Effective AdSense' => $this->onOff($this->advertising->isEnabled())],
            ['AdSense client syntax' => $this->syntax(
                $this->advertising->hasConfiguredClient(),
                $this->advertising->hasValidClient()
            )],
            ['ads.txt expected' => $this->yesNo($this->advertising->isEnabled())],
            ['Ad-safe routes' => implode(', ', self::AD_SAFE_ROUTES)],
            ['Rewarded integration' => 'Google AdSense Offerwall (account-side; no application completion callback)'],
        );
        $io->definitionList(
            ['Effective Analytics' => $this->onOff($this->analytics->isEnabled())],
            ['GA measurement id syntax' => $this->syntax(
                $this->analytics->hasConfiguredMeasurementId(),
                $this->analytics->hasValidMeasurementId()
            )],
        );
        $io->definitionList(
            ['Consent provider' => $this->consent->provider() ?? 'none'],
            ['Analytics consent source' => $this->analyticsConsentSource()],
            ['Google-compatible CSP' => $this->onOff($this->contentSecurityPolicy->googleScriptsEnabled())],
            ['Google-free routes' => $googleFree === [] ? 'none' : implode(', ', $googleFree)],
            ['CSP profile on those routes' => $this->googleFreeCspProfile($googleFree)],
            ['Expected privacy-policy URL' => $this->publicUrl->to('/privacy')],
            ['Runtime config mode' => $mode],
        );

        $this->reportWarnings($io, $mode);

        $io->note(sprintf(
            'Compare the expected privacy-policy URL with AdSense → Privacy & messaging → European regulations → Your sites. '
            .'This application has no AdSense management-API access, so it reports the URL it serves, not the one your account is set to. '
            .'That page must contain no Funding Choices tag and no other consent-requiring script: %s.',
            $googleFree === [] ? 'no route is currently protected' : implode(', ', $googleFree).' are served Google-free'
        ));
        $io->note('Visible ads, Offerwall inventory and measured traffic are Google decisions. Verify script requests, browser CSP reports, the consent UI and AdSense/GA4 account settings separately.');

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

    private function reportWarnings(SymfonyStyle $io, string $mode): void
    {
        if ($this->advertising->hasValidClient() && !$this->advertising->isEnabled()) {
            $io->note('ADSENSE_CLIENT holds a valid publisher id while ADSENSE_ENABLED is false. It has no effect: a credential never enables or selects a feature here.');
        }
        if (!$this->advertising->isEnabled() && $this->advertising->hasConfiguredClient() && !$this->advertising->hasValidClient()) {
            $io->warning('ADSENSE_CLIENT is set but is not a valid ca-pub- publisher id. AdSense is disabled; Analytics and every application feature are unaffected.');
        }
        if ($this->analytics->hasConfiguredMeasurementId() && !$this->analytics->hasValidMeasurementId()) {
            $io->warning('GOOGLE_ANALYTICS_MEASUREMENT_ID is set but is not a valid G- measurement id. Analytics is disabled; AdSense and its consent platform are unaffected.');
        }
        if ($this->consent->provider() === ConsentConfiguration::PROVIDER_GOOGLE && $this->consent->coversAnalytics()) {
            $io->note(
                'Analytics reads its decision from the Google CMP and stays off until that decision is granted. '
                .'If it never becomes granted, check AdSense → Privacy & messaging → European regulations → Settings and enable both '
                .'"Consent mode for advertising purposes" and "Consent mode for analytics purposes".'
            );
        }
        if (str_starts_with($mode, 'compiled dotenv')) {
            $io->warning('Symfony is reading .env.local.php. Changes to .env.local will not take effect until the compiled file is regenerated or removed.');
        }
    }

    private function analyticsConsentSource(): string
    {
        if (!$this->consent->coversAnalytics()) {
            return 'none (Analytics is off)';
        }

        return $this->consent->provider() === ConsentConfiguration::PROVIDER_GOOGLE
            ? 'Google CMP (Privacy & messaging analytics purpose)'
            : 'local Analytics-only consent';
    }

    /** @param list<string> $googleFree */
    private function googleFreeCspProfile(array $googleFree): string
    {
        if ($googleFree === []) {
            return 'n/a';
        }
        $relaxed = array_filter(
            $googleFree,
            fn (string $path): bool => $this->contentSecurityPolicy->googleScriptsEnabledFor($path)
        );

        return $relaxed === []
            ? "strict, no Google origins, no nonce"
            : 'MISCONFIGURED: Google-capable on '.implode(', ', $relaxed);
    }

    private function syntax(bool $configured, bool $valid): string
    {
        if (!$configured) {
            return 'not configured';
        }

        return $valid ? 'valid' : 'invalid';
    }

    private function onOff(bool $value): string
    {
        return $value ? 'enabled' : 'disabled';
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
