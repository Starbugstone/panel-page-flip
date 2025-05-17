<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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
 * It provides a rudimentary way to check if the core authentication-related API endpoints
 * are functioning as expected. It uses the Symfony HTTP Client to make requests.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec panel-page-flip_php php bin/console app:test-api-endpoints
 *
 *    Replace `panel-page-flip_php` with the actual name of your PHP service container if different.
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:test-api-endpoints
 *
 *    Ensure your local environment is configured, especially the .env variables for APP_SCHEME, APP_HOST,
 *    and APP_PORT, so the command can generate correct absolute URLs to your application.
 *    The application's web server should be running and accessible at the configured address (e.g., http://localhost:8000 or http://localhost:80 if run inside docker targeting itself).
 *
 * Important Considerations:
 * - This command makes live HTTP requests to your application. Ensure your application (web server) is running.
 * - It generates a unique email for each run to avoid conflicts with existing users during registration tests.
 * - The command relies on the correct setup of `APP_SCHEME`, `APP_HOST`, and `APP_PORT` in your .env file
 *   (or equivalent Symfony configuration) for the `UrlGeneratorInterface` to build correct absolute URLs,
 *   especially when run from the CLI.
 * - The output will indicate the success or failure of each test step and show response status codes and content.
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $testUserEmail = 'testuser_' . uniqid() . '@example.com';
        $testUserPassword = 'Password123!';

        $io->section('Testing API Endpoints');

        // 1. Test Registration
        $io->writeln('Attempting to register new user: ' . $testUserEmail);
        $registerUrl = $this->urlGenerator->generate('api_register', [], UrlGeneratorInterface::ABSOLUTE_URL);
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
}
