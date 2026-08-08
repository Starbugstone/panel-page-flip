<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tests basic API endpoints like user registration and login.
 *
 * This command performs a sequence of automated tests against the API:
 * 1. Attempts to register a new unique user.
 * 2. Attempts to log in using the credentials of the newly registered user.
 * 3. Attempts to log in using incorrect credentials for the newly registered user.
 *
 * Probe URLs (HTTP requests from this CLI process) use, in order:
 *   --base-url → APP_INTERNAL_URL → APP_URL (via UrlGenerator ABSOLUTE_URL).
 * Keep APP_URL as the public origin for emails/OAuth. Inside Docker Compose,
 * APP_INTERNAL_URL=http://nginx so the PHP container can reach the web server.
 *
 * Usage:
 *   docker compose exec php php bin/console app:test-api-endpoints
 *   php bin/console app:test-api-endpoints --base-url=http://127.0.0.1:8080
 */
#[AsCommand(
    name: 'app:test-api-endpoints',
    description: 'Tests basic API endpoints like registration and login.',
)]
class TestApiEndpointsCommand extends Command
{
    private HttpClientInterface $client;
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(HttpClientInterface $client, UrlGeneratorInterface $urlGenerator)
    {
        parent::__construct();
        $this->client = $client;
        $this->urlGenerator = $urlGenerator;
    }

    protected function configure(): void
    {
        $this->addOption(
            'base-url',
            null,
            InputOption::VALUE_REQUIRED,
            'Base URL for live HTTP probes (overrides APP_INTERNAL_URL / APP_URL)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $testUserEmail = 'testuser_' . uniqid() . '@example.com';
        $testUserPassword = 'Password123!';

        $io->section('Testing API Endpoints');

        // 1. Test Registration
        $io->writeln('Attempting to register new user: ' . $testUserEmail);
        $registerUrl = $this->probeUrl('api_register', $input->getOption('base-url'));
        $io->writeln('[DEBUG] Generated registration URL: ' . $registerUrl);
        
        try {
            $response = $this->client->request('POST', $registerUrl, [
                'json' => [
                    'email' => $testUserEmail,
                    'password' => $testUserPassword, // Using password instead of plainPassword to match controller
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);

            if ($response->getStatusCode() === 201) {
                $io->success('Registration successful (Status 201).');
                $io->writeln('Response: ' . $response->getContent(false)); // false to not throw on non-2xx
            } else {
                $io->warning(sprintf('Registration failed. Status: %d', $response->getStatusCode()));
                $io->writeln('Response: ' . $response->getContent(false));
            }
        } catch (\Exception $e) {
            $io->error('Registration request failed: ' . $e->getMessage());
        }
        $io->newLine();

        // 2. Test Login with correct credentials
        $io->writeln('Attempting to login with correct credentials for: ' . $testUserEmail);
        // For json_login, the check_path is /api/login. We construct it based on the registration URL's base.
        $baseUrl = preg_replace('/\/api\/register$/', '', $registerUrl);
        $loginUrl = $baseUrl . '/api/login';
        $io->writeln('[DEBUG] Generated login URL: ' . $loginUrl);

        try {
            $response = $this->client->request('POST', $loginUrl, [
                'json' => [
                    'email' => $testUserEmail,
                    'password' => $testUserPassword,
                ],
                 'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);

            // Symfony's JsonLoginAuthenticator by default returns 200 on success
            if ($response->getStatusCode() === 200) {
                $io->success('Login successful (Status 200).');
                $io->writeln('Response: ' . $response->getContent(false));
            } else {
                $io->warning(sprintf('Login failed. Status: %d', $response->getStatusCode()));
                $io->writeln('Response: ' . $response->getContent(false));
            }
        } catch (\Exception $e) {
            $io->error('Login request failed: ' . $e->getMessage());
        }
        $io->newLine();

        // 3. Test Login with incorrect credentials
        $io->writeln('Attempting to login with incorrect credentials for: ' . $testUserEmail);
        try {
            $response = $this->client->request('POST', $loginUrl, [
                'json' => [
                    'email' => $testUserEmail,
                    'password' => 'WrongPassword!',
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]
            ]);

            // Symfony's JsonLoginAuthenticator by default returns 401 on failure
            if ($response->getStatusCode() === 401) {
                $io->success('Login failed as expected (Status 401).');
                $io->writeln('Response: ' . $response->getContent(false));
            } else {
                $io->warning(sprintf('Login with incorrect credentials gave unexpected status: %d', $response->getStatusCode()));
                $io->writeln('Response: ' . $response->getContent(false));
            }
        } catch (\Exception $e) {
            $io->error('Login request with incorrect credentials failed: ' . $e->getMessage());
        }

        $io->section('API Endpoint Tests Completed.');
        return Command::SUCCESS;
    }

    private function probeUrl(string $route, ?string $baseUrlOverride): string
    {
        $base = $baseUrlOverride
            ?: (getenv('APP_INTERNAL_URL') ?: '')
            ?: '';

        if ($base !== '') {
            return rtrim($base, '/').'/'.ltrim(
                $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_PATH),
                '/'
            );
        }

        return $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
