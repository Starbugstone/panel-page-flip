<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * The switch, through the real parameter, subscriber, limiter and login route.
 *
 * The allowance is raised for the test environment, so each case spends all but
 * two of it in process and then makes the failed logins that matter.
 */
final class LoginThrottlingTest extends AbstractApiTestCase
{
    private const SWITCH = 'LOGIN_RATE_LIMIT_ENABLED';
    private const CLIENT_IP = '127.0.0.1';
    private const ALLOWANCE_LEFT = 2;

    private mixed $originalEnv;
    private mixed $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = $_ENV[self::SWITCH] ?? null;
        $this->originalServer = $_SERVER[self::SWITCH] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restore($_ENV, $this->originalEnv);
        $this->restore($_SERVER, $this->originalServer);

        parent::tearDown();
    }

    public function testRepeatedFailedLoginsAreThrottledWhenEnabled(): void
    {
        $this->rebootWithSwitch('1');

        self::assertSame([401, 401, 429, 429], $this->repeatedFailedLogins());
        self::assertSame(429, $this->correctLogin(), 'A throttled address cannot sign in even with the right password.');
    }

    public function testRepeatedFailedLoginsAreNotThrottledWhenDisabled(): void
    {
        $this->rebootWithSwitch('0');

        self::assertSame([401, 401, 401, 401], $this->repeatedFailedLogins());
        self::assertSame(200, $this->correctLogin());
    }

    /** A server whose configuration never mentions the key must be protected. */
    public function testRepeatedFailedLoginsAreThrottledWhenTheSwitchIsAbsent(): void
    {
        $this->rebootWithSwitch(null);

        self::assertSame([401, 401, 429, 429], $this->repeatedFailedLogins());
    }

    /**
     * The file every deployment inherits has to fail safe, and the two files
     * that turn the control off are the only ones allowed to.
     */
    public function testCommittedEnvironmentFilesOnlyDisableItForDevelopmentAndTests(): void
    {
        self::assertSame('1', $this->committedValue('.env'));
        self::assertSame('1', $this->committedValue('.env.example'));
        self::assertSame('0', $this->committedValue('.env.dev'));
        self::assertSame('0', $this->committedValue('.env.test'));
    }

    private function committedValue(string $file): ?string
    {
        $path = self::getContainer()->getParameter('kernel.project_dir').'/'.$file;

        return (new Dotenv())->parse((string) file_get_contents($path), $path)[self::SWITCH] ?? null;
    }

    /** The value is read when the container resolves it, so it takes a new kernel. */
    private function rebootWithSwitch(?string $value): void
    {
        $this->restore($_ENV, $value);
        $this->restore($_SERVER, $value);

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    /** @param array<string, mixed> $variables */
    private function restore(array &$variables, mixed $value): void
    {
        if ($value === null) {
            unset($variables[self::SWITCH]);

            return;
        }

        $variables[self::SWITCH] = $value;
    }

    /** @return list<int> the status of each attempt */
    private function repeatedFailedLogins(): array
    {
        UserFactory::createOne(['email' => 'throttled@test.local']);

        $limiter = static::getContainer()->get('test.limiter.login')->create(self::CLIENT_IP);
        $limiter->consume(1000 - self::ALLOWANCE_LEFT);

        $statuses = [];
        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/login', ['email' => 'throttled@test.local', 'password' => 'Wrong!Password123']);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        return $statuses;
    }

    private function correctLogin(): int
    {
        $this->postJson('/api/login', ['email' => 'throttled@test.local', 'password' => UserFactory::PASSWORD]);

        return $this->client->getResponse()->getStatusCode();
    }
}
