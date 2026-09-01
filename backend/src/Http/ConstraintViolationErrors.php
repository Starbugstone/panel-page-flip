<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Validator\ConstraintViolationInterface;

enum ConstraintViolationErrors
{
    /**
     * @param iterable<ConstraintViolationInterface> $violations
     *
     * @return array<string, list<string>>
     */
    public static function from(iterable $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $field = (string) $violation->getPropertyPath();
            $errors[$field !== '' ? $field : 'form'][] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
