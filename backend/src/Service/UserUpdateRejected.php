<?php

declare(strict_types=1);

namespace App\Service;

final class UserUpdateRejected extends \RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array{message: string, errors?: array<string, list<string>>} */
    public function payload(): array
    {
        $payload = ['message' => $this->getMessage()];
        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        return $payload;
    }
}
