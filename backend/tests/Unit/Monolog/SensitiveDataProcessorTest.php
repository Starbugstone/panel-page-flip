<?php

namespace App\Tests\Unit\Monolog;

use App\Monolog\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * The last line between a logger call and a file somebody else can read.
 *
 * The rule this pins is that redaction does not depend on the caller having
 * remembered. Every case below is written the careless way on purpose — a whole
 * request body, a raw exception message, an integration's stored credentials —
 * because those are the calls that actually leak, and the ones a processor
 * exists for.
 */
final class SensitiveDataProcessorTest extends TestCase
{
    /**
     * Stands in for a credential everywhere below.
     *
     * Deliberately a readable phrase rather than a random-looking string.
     * Secret scanners judge these fixtures on entropy and shape, and a test file
     * full of convincing-looking tokens produces a stream of incidents that
     * somebody eventually learns to dismiss without reading — which is how a
     * real leak gets waved through. It still satisfies every pattern the
     * processor matches on, which is all the tests need of it.
     */
    private const FAKE_CREDENTIAL = 'EXAMPLE-NOT-A-REAL-CREDENTIAL';

    public function testRedactsAPlaintextPassword(): void
    {
        $context = $this->process(['email' => 'reader@example.test', 'password' => 'hunter2']);

        self::assertSame(SensitiveDataProcessor::REDACTED, $context['password']);
        // The rest of the record survives, or redaction would just be deletion.
        self::assertSame('reader@example.test', $context['email']);
    }

    public function testRedactsAPasswordHash(): void
    {
        $context = $this->process([
            'passwordHash' => '$2y$13$abcdefghijklmnopqrstuv',
            'password_hash' => '$argon2id$v=19$m=65536',
        ]);

        self::assertSame(SensitiveDataProcessor::REDACTED, $context['passwordHash']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['password_hash']);
    }

    public function testRedactsAccessAndRefreshTokens(): void
    {
        $context = $this->process([
            'access_token' => 'sl.' . self::FAKE_CREDENTIAL,
            'refreshToken' => 'refresh-me',
            'dropbox_refresh_token' => 'stored-grant',
        ]);

        self::assertSame(SensitiveDataProcessor::REDACTED, $context['access_token']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['refreshToken']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['dropbox_refresh_token']);
    }

    public function testRedactsAuthorizationHeadersAndCookies(): void
    {
        $context = $this->process([
            'Authorization' => 'Bearer ' . self::FAKE_CREDENTIAL,
            'cookie' => 'PHPSESSID=' . self::FAKE_CREDENTIAL,
            'Set-Cookie' => 'XSRF-TOKEN=' . self::FAKE_CREDENTIAL,
        ]);

        self::assertSame(SensitiveDataProcessor::REDACTED, $context['Authorization']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['cookie']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['Set-Cookie']);
    }

    public function testRedactsApiKeysAndClientSecrets(): void
    {
        $context = $this->process([
            'api_key' => 'metron-key',
            'apiKey' => 'comicvine-key',
            'client_secret' => 'dropbox-app-secret',
            'smtp_password' => 'mailer-password',
        ]);

        foreach (['api_key', 'apiKey', 'client_secret', 'smtp_password'] as $key) {
            self::assertSame(SensitiveDataProcessor::REDACTED, $context[$key]);
        }
    }

    /**
     * Nesting is where redaction usually fails: somebody logs a whole payload
     * and the sensitive key is three levels down inside it.
     */
    public function testRedactsRecursively(): void
    {
        $context = $this->process([
            'request' => [
                'headers' => ['authorization' => 'Bearer ' . self::FAKE_CREDENTIAL],
                'body' => ['user' => ['email' => 'nested@example.test', 'password' => 'nested-secret']],
            ],
        ]);

        self::assertSame(SensitiveDataProcessor::REDACTED, $context['request']['headers']['authorization']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['request']['body']['user']['password']);
        self::assertSame('nested@example.test', $context['request']['body']['user']['email']);
    }

    public function testKeepsTheIdentifiersAnAuditRecordExistsFor(): void
    {
        $context = $this->process([
            'event' => 'audit.share.adult_confirmed',
            'actor_user_id' => 42,
            'target_id' => 7,
            'comic_id' => 3,
            'result' => 'success',
            'confirmed_at' => '2026-08-08T10:00:00+00:00',
            'count' => 11,
        ]);

        self::assertSame('audit.share.adult_confirmed', $context['event']);
        self::assertSame(42, $context['actor_user_id']);
        self::assertSame(7, $context['target_id']);
        self::assertSame(3, $context['comic_id']);
        self::assertSame('success', $context['result']);
        self::assertSame('2026-08-08T10:00:00+00:00', $context['confirmed_at']);
        self::assertSame(11, $context['count']);
    }

    /**
     * A key can name a credential and still hold a number.
     *
     * The retention record's whole purpose is to show that expired tokens were
     * removed, so redacting the counts would strike out the proof and leave a
     * line that says nothing. These are the shapes the application actually
     * writes — every one of them was redacted before.
     */
    public function testKeepsCountsAndTimestampsThatMerelyNameACredential(): void
    {
        $context = $this->process([
            'verification_tokens_deleted' => 1,
            'reset_tokens_deleted' => 4,
            'tokensRevoked' => 2,
            'invalid_token_attempts' => 9,
            'password_changed_at' => '2026-08-08T10:00:00+00:00',
            'passwordChangedAt' => '2026-08-08T10:00:00+00:00',
            'hashAlgorithm' => 'argon2id',
        ]);

        self::assertSame(1, $context['verification_tokens_deleted']);
        self::assertSame(4, $context['reset_tokens_deleted']);
        self::assertSame(2, $context['tokensRevoked']);
        self::assertSame(9, $context['invalid_token_attempts']);
        self::assertSame('2026-08-08T10:00:00+00:00', $context['password_changed_at']);
        self::assertSame('2026-08-08T10:00:00+00:00', $context['passwordChangedAt']);
        self::assertSame('argon2id', $context['hashAlgorithm']);
    }

    /**
     * The counting rule reads the final word and nothing else, so a key that
     * merely contains one of those words is still a key that holds the secret.
     */
    public function testTheCountingRuleDoesNotOpenTheDoorForTheCredentialItself(): void
    {
        $context = $this->process([
            'reset_token' => self::FAKE_CREDENTIAL,
            'token_count_value' => self::FAKE_CREDENTIAL,
            'deleted_account_password' => 'hunter2',
            'client_secret_format' => self::FAKE_CREDENTIAL,
        ]);

        self::assertSame(SensitiveDataProcessor::REDACTED, $context['reset_token']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['token_count_value']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['deleted_account_password']);
        self::assertSame(SensitiveDataProcessor::REDACTED, $context['client_secret_format']);
    }

    /**
     * Key matching cannot catch a secret embedded in a sentence, and those
     * arrive constantly: an exception message quoting the URL it called, a
     * request line copied into a debug log.
     */
    public function testRedactsSecretsEmbeddedInStrings(): void
    {
        $context = $this->process([
            'message' => 'GET https://api.dropboxapi.com/2/files?access_token=sl.' . self::FAKE_CREDENTIAL . ' failed',
            'header_line' => 'Authorization: Bearer ' . self::FAKE_CREDENTIAL,
            'invitation_url' => 'https://example.test/share/invitation?token=' . self::FAKE_CREDENTIAL,
        ]);

        self::assertStringNotContainsString(self::FAKE_CREDENTIAL, $context['message']);
        // The shape of the call is still readable, which is the whole point of
        // redacting rather than dropping the record.
        self::assertStringContainsString('api.dropboxapi.com', $context['message']);

        self::assertStringNotContainsString(self::FAKE_CREDENTIAL, $context['header_line']);
        self::assertStringNotContainsString(self::FAKE_CREDENTIAL, $context['invitation_url']);
    }

    /**
     * The message is redacted too. Our own events use fixed names, but this
     * processor runs on every channel, and an interpolated URL in an ordinary
     * warning is the leak nobody plans for.
     */
    public function testRedactsTheRecordMessageAsWellAsTheContext(): void
    {
        $record = $this->processRecord([], 'Callback failed: https://example.test/cb?token=' . self::FAKE_CREDENTIAL);

        self::assertStringNotContainsString(self::FAKE_CREDENTIAL, $record->message);
        self::assertStringContainsString('Callback failed', $record->message);
    }

    /**
     * A context nested past the guard is replaced rather than walked. The depth
     * limit is there so a self-referential structure cannot spin, and the
     * replacement has to be a redaction — dropping the branch silently would
     * hide whatever was in it.
     */
    public function testAContextNestedTooDeeplyIsReplacedRatherThanWalked(): void
    {
        $deep = ['password' => 'PlaintextPassword!1'];
        for ($level = 0; $level < 12; ++$level) {
            $deep = ['nested' => $deep];
        }

        $rendered = var_export($this->process($deep), true);

        self::assertStringContainsString('context nested too deeply to sanitise', $rendered);
        self::assertStringNotContainsString('PlaintextPassword!1', $rendered);
    }

    public function testLeavesExceptionObjectsForTheFormatter(): void
    {
        $exception = new \RuntimeException('Something broke');
        $record = $this->processRecord(['exception' => $exception, 'user_id' => 9]);

        // Untouched, because the formatter is what knows how to render one and
        // replacing it would break every existing handler.
        self::assertSame($exception, $record->context['exception']);
        self::assertSame(9, $record->context['user_id']);
    }

    public function testRedactsTheExtraArrayToo(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'security',
            Level::Warning,
            'security.authentication.failed',
            [],
            ['token' => 'from-a-processor', 'route' => 'api_login'],
        );

        $processed = (new SensitiveDataProcessor())($record);

        self::assertSame(SensitiveDataProcessor::REDACTED, $processed->extra['token']);
        self::assertSame('api_login', $processed->extra['route']);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function process(array $context): array
    {
        return $this->processRecord($context)->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function processRecord(array $context, string $message = 'security.event'): LogRecord
    {
        return (new SensitiveDataProcessor())(new LogRecord(
            new \DateTimeImmutable(),
            'security',
            Level::Warning,
            $message,
            $context,
        ));
    }
}
