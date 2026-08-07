<?php

namespace App\Service;

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
