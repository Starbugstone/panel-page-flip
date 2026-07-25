<?php

namespace App\Service;

class PasswordValidator
{
    /**
     * @return list<string>
     */
    public function validate(string $password): array
    {
        $errors = [];

        if (strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters long.';
        }

        if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must include both uppercase and lowercase letters.';
        }

        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Password must include at least one digit.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must include at least one symbol.';
        }

        return $errors;
    }
}
