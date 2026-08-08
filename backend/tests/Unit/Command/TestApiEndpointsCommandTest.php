<?php

namespace App\Tests\Unit\Command;

use App\Command\TestApiEndpointsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class TestApiEndpointsCommandTest extends TestCase
{
    public function testSuccessfulProbeUsesTheOverrideAndCurrentRegistrationContract(): void
    {
        $requests = [];
        $responses = [
            new MockResponse('{"message":"registered"}', ['http_code' => 201]),
            new MockResponse('{"requiresVerification":true}', ['http_code' => 403]),
            new MockResponse('{"message":"Invalid credentials."}', ['http_code' => 401]),
        ];
        $responseIndex = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (
            &$requests,
            &$responseIndex,
            $responses
        ): MockResponse {
            $requests[] = [$method, $url, $options];

            return $responses[$responseIndex++];
        });

        $tester = new CommandTester(new TestApiEndpointsCommand($client, $this->urlGenerator()));
        $status = $tester->execute(['--base-url' => 'http://internal']);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertSame(
            ['http://internal/api/register', 'http://internal/api/login', 'http://internal/api/login'],
            array_column($requests, 1)
        );

        $registration = json_decode((string) $requests[0][2]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($registration['agreeTerms']);
        self::assertSame('Password123!', $registration['password']);
        self::assertStringContainsString('Unverified login refused as expected', $tester->getDisplay());
    }

    public function testUnexpectedApiResponseMakesTheCommandFail(): void
    {
        $responses = [
            new MockResponse('{"message":"bad request"}', ['http_code' => 400]),
            new MockResponse('{"requiresVerification":true}', ['http_code' => 403]),
            new MockResponse('{"message":"Invalid credentials."}', ['http_code' => 401]),
        ];
        $responseIndex = 0;
        $client = new MockHttpClient(static function () use (&$responseIndex, $responses): MockResponse {
            return $responses[$responseIndex++];
        });

        $tester = new CommandTester(new TestApiEndpointsCommand($client, $this->urlGenerator()));

        self::assertSame(Command::FAILURE, $tester->execute(['--base-url' => 'http://internal']));
        self::assertStringContainsString('Registration failed. Status: 400', $tester->getDisplay());
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            static fn (string $route): string => match ($route) {
                'api_register' => '/api/register',
                'api_login' => '/api/login',
                default => throw new \LogicException('Unexpected route: '.$route),
            }
        );

        return $generator;
    }
}
