<?php

namespace App\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Strips credentials out of every log record before a handler ever sees one.
 *
 * Registered on the logger prototype, so it runs for all channels — the normal
 * application log included. Redaction that only covered the security channel
 * would be the wrong way round: a token leaks just as badly from an exception
 * context in `main`, and the calls that leak are the ones nobody remembered to
 * think about.
 *
 * Two passes, because secrets arrive in two shapes. Keys named after what they
 * hold (`password`, `access_token`, `Authorization`) are replaced wholesale.
 * Values that carry a secret inside a larger string — a URL with `?token=`, an
 * `Authorization: Bearer` line copied out of a request dump — are rewritten in
 * place, so the surrounding text survives for diagnosis and the secret does not.
 *
 * Exception objects are passed through untouched, message included. Rewriting
 * one means constructing a different exception — losing its class, its previous
 * chain and anything a formatter or handler reads off it — and a redaction step
 * that can break how a fatal error is reported is a bad trade. So a `Throwable`
 * is the formatter's business, and this is a real boundary rather than an
 * oversight: an exception built from a URL with a token in it will carry that
 * token into the log. Do not log one.
 */
final class SensitiveDataProcessor implements ProcessorInterface
{
    public const REDACTED = '[redacted]';

    /**
     * Matched against the whole key, case-insensitively, with separators
     * ignored — so `access_token`, `accessToken` and `ACCESS-TOKEN` are one
     * pattern, and a key merely containing the word (`dropbox_refresh_token`,
     * `user_password_field`) still matches, because guessing which halves of a
     * name are safe is how secrets get through. The one exception is a key
     * whose last word says it holds a count or a time; see
     * {@see METADATA_SUFFIXES}.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'cookie',
        'apikey',
        'credential',
        'privatekey',
        'signature',
        'sessionid',
        'csrf',
        'salt',
        'hash',
        'dsn',
    ];

    /**
     * Endings that mean a key describes a secret rather than holding one — a
     * count, a tally, a server-generated timestamp. `reset_tokens_deleted` is a
     * number and `reset_token` is a credential, and without this the retention
     * record that exists to prove the promise was kept would say `[redacted]`
     * exactly where the proof belongs.
     *
     * Matched against the key's final *word*, not anywhere inside it. As a
     * substring this would let `token_count_value` through; as a whole-key
     * allowlist it would need revising every time a caller worded one
     * differently, which is how the two keys above came to be redacted in the
     * first place.
     *
     * @var list<string>
     */
    private const METADATA_SUFFIXES = [
        'count',
        'deleted',
        'remaining',
        'revoked',
        'attempts',
        'algorithm',
        'at',
    ];

    /** @var list<non-empty-string> */
    private const SENSITIVE_VALUE_PATTERNS = [
        // `?token=abc`, `&access_token=abc`, `client_secret=abc` in a URL or a
        // form body. The name is kept so the record still says what was there.
        '/((?:access_|refresh_|reset_|invitation_|share_|csrf_|id_|api_)?(?:token|secret|password|key|code)=)[^\s&"\'\\\\]+/i',
        // `Authorization: Bearer xyz`, and the bare scheme+credential form.
        '/(\b(?:Bearer|Basic|Token)\s+)[A-Za-z0-9\-._~+\/=]{8,}/i',
        // A Dropbox short-lived access token, which travels on its own often
        // enough that it turns up outside any recognisable key=value shape.
        '/\bsl\.[A-Za-z0-9\-._~+\/=]{20,}/',
    ];

    /** Deep enough for any context this application builds; a guard, not a limit. */
    private const MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            // The message too. The security and audit channels use fixed event
            // names, but this processor runs everywhere, and an ordinary
            // `$logger->warning("... $url ...")` interpolating a callback or an
            // API endpoint is exactly the leak nobody plans.
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context, 0),
            extra: $this->redactArray($record->extra, 0),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [self::REDACTED => 'context nested too deeply to sanitise'];
        }

        $sanitised = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $sanitised[$key] = self::REDACTED;
                continue;
            }

            $sanitised[$key] = $this->redactValue($value, $depth + 1);
        }

        return $sanitised;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->redactArray($value, $depth);
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        // Anything else — scalars, exceptions, whatever a caller passed — is
        // handed on untouched. Walking objects would mean deciding what an
        // arbitrary graph means, and the formatter is the one that knows.
        return $value;
    }

    private function redactString(string $value): string
    {
        foreach (self::SENSITIVE_VALUE_PATTERNS as $pattern) {
            $replaced = preg_replace_callback(
                $pattern,
                static fn (array $matches): string => (isset($matches[1]) ? $matches[1] : '') . self::REDACTED,
                $value
            );

            if ($replaced !== null) {
                $value = $replaced;
            }
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        if (in_array($this->lastWordOf($key), self::METADATA_SUFFIXES, true)) {
            return false;
        }

        $normalised = strtolower(str_replace(['_', '-', '.', ' '], '', $key));

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($normalised, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The final word of a key, reading `snake_case`, `kebab-case` and
     * `camelCase` the same way — the same indifference to spelling the
     * sensitive patterns already have.
     */
    private function lastWordOf(string $key): string
    {
        $words = preg_split('/[_\-. ]+|(?<=[a-z0-9])(?=[A-Z])/', $key, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        return strtolower((string) end($words));
    }
}
