<?php

namespace App\Service;

use Symfony\Component\RateLimiter\RateLimit;

/**
 * A sharing operation that failed for a reason the caller should be told about,
 * carrying the HTTP status that reason maps to.
 *
 * Messages on this exception are written to be read by the person who triggered
 * the request; anything internal is logged instead and surfaces as a generic
 * failure.
 */
class ShareException extends \RuntimeException
{
    /** The sender did not acknowledge responsibility for what they are sharing. */
    public const CODE_RESPONSIBILITY_REQUIRED = 'share_responsibility_acknowledgement_required';

    /** The recipient has not declared they are 18 or older. */
    public const CODE_ADULT_CONFIRMATION_REQUIRED = 'adult_confirmation_required';

    /**
     * A real sharing code, of a kind this endpoint does not take.
     *
     * Distinct from "invalid" so the client can show the guidance the message
     * carries — where this code *does* go — rather than the generic failure it
     * shows for a code that resolves to nothing.
     */
    public const CODE_WRONG_CODE_TYPE = 'share_code_wrong_type';

    /**
     * @param string|null $errorCode a stable identifier for failures the client
     *                               has to react to rather than merely display,
     *                               such as opening the age gate. Most failures
     *                               need none: their message is the whole
     *                               response.
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * A refusal by a rate limiter, told to the person who hit it.
     *
     * Every allowance in sharing refuses the same way — say what there was too
     * much of, then say when to come back — and the second half is identical
     * wherever it appears. Only the first half is the caller's to write, so only
     * the first half is asked for; the wait is read off the limiter rather than
     * recomputed, and the status is not a decision any caller has to get right.
     *
     * @param string $whatHappened a complete sentence naming what was refused,
     *                             ending in a full stop — "You have sent too
     *                             many invitations recently."
     */
    public static function rateLimited(string $whatHappened, RateLimit $limit): self
    {
        return new self(
            sprintf('%s Please try again in %d minute(s).', $whatHappened, RateLimitRetry::minutes($limit)),
            429
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The JSON body describing this failure, with the code only where there is
     * one to give.
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        $payload = ['message' => $this->getMessage()];
        if ($this->errorCode !== null) {
            $payload['code'] = $this->errorCode;
        }

        return $payload;
    }
}
