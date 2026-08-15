<?php

namespace App\Service;

final class ContentReportValidationException extends \DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The report could not be submitted.');
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
