<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TurnstileConfiguration;
use App\Service\TurnstileRejectedException;
use App\Service\TurnstileUnavailableException;
use App\Service\TurnstileVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TurnstileVerifierTest extends TestCase
{
    public function testItPostsTheSecretTokenAndClientIpAndChecksTheResponseContext(): void
    {
        $seen = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body'] ?? null];

            return $this->response(success: true);
        });

        $this->verifier($http)->verify('browser-token', '203.0.113.9');

        self::assertSame('POST', $seen[0]);
        self::assertSame(TurnstileVerifier::SITEVERIFY_URL, $seen[1]);
        parse_str((string) $seen[2], $body);
        self::assertSame([
            'secret' => 'test-secret',
            'response' => 'browser-token',
            'remoteip' => '203.0.113.9',
        ], $body);
    }

    /** @dataProvider invalidLocalTokenProvider */
    public function testItRejectsInvalidLocalTokensWithoutCallingCloudflare(mixed $token): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('Siteverify must not be called.');
        });

        $this->expectException(TurnstileRejectedException::class);

        $this->verifier($http)->verify($token, null);
    }

    public static function invalidLocalTokenProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong type' => [['token']];
        yield 'empty' => ['   '];
        yield 'over maximum' => [str_repeat('x', 2049)];
    }

    /** @dataProvider rejectedResponseProvider */
    public function testItRejectsFailedExpiredOrWrongContextResponses(array $payload): void
    {
        $this->expectException(TurnstileRejectedException::class);

        $this->verifier(new MockHttpClient(new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR))))
            ->verify('browser-token', null);
    }

    public static function rejectedResponseProvider(): iterable
    {
        yield 'invalid token' => [[
            'success' => false,
            'error-codes' => ['invalid-input-response'],
        ]];
        yield 'expired or duplicate token' => [[
            'success' => false,
            'error-codes' => ['timeout-or-duplicate'],
        ]];
        yield 'wrong action' => [[
            'success' => true,
            'action' => 'login',
            'hostname' => 'panel.example',
        ]];
        yield 'wrong hostname' => [[
            'success' => true,
            'action' => TurnstileVerifier::ACTION,
            'hostname' => 'foreign.example',
        ]];
    }

    /** @dataProvider unavailableResponseProvider */
    public function testItDistinguishesProviderOrConfigurationFailuresFromRejectedChallenges(MockResponse $response): void
    {
        $this->expectException(TurnstileUnavailableException::class);

        $this->verifier(new MockHttpClient($response))->verify('browser-token', null);
    }

    public static function unavailableResponseProvider(): iterable
    {
        yield 'provider 5xx' => [new MockResponse('{}', ['http_code' => 503])];
        yield 'malformed response' => [new MockResponse('not-json')];
        yield 'provider internal error' => [new MockResponse('{"success":false,"error-codes":["internal-error"]}')];
        yield 'invalid configured secret' => [new MockResponse('{"success":false,"error-codes":["invalid-input-secret"]}')];
    }

    public function testTransportFailuresAreUnavailable(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('no route to host');
        });

        $this->expectException(TurnstileUnavailableException::class);

        $this->verifier($http)->verify('browser-token', null);
    }

    private function verifier(MockHttpClient $http): TurnstileVerifier
    {
        return new TurnstileVerifier(
            $http,
            new TurnstileConfiguration(true, 'test-site', 'test-secret', 'https://panel.example')
        );
    }

    private function response(bool $success): MockResponse
    {
        return new MockResponse(json_encode([
            'success' => $success,
            'action' => TurnstileVerifier::ACTION,
            'hostname' => 'panel.example',
        ], JSON_THROW_ON_ERROR));
    }
}
