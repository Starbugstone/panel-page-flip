<?php

namespace App\Tests\Unit\Service;

use App\Service\PasswordValidator;
use PHPUnit\Framework\TestCase;

class PasswordValidatorTest extends TestCase
{
    private PasswordValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PasswordValidator();
    }

    public function testStrongPasswordHasNoErrors(): void
    {
        self::assertSame([], $this->validator->validate('Str0ng!Password'));
    }

    public function testTooShortReturnsLengthError(): void
    {
        $errors = $this->validator->validate('Aa1!');

        self::assertContains('Password must be at least 12 characters long.', $errors);
    }

    public function testMissingMixedCaseReportsCaseError(): void
    {
        $errors = $this->validator->validate('alllowercase1!');

        self::assertContains('Password must include both uppercase and lowercase letters.', $errors);
    }

    public function testMissingDigitReportsDigitError(): void
    {
        $errors = $this->validator->validate('NoDigitsHere!!');

        self::assertContains('Password must include at least one digit.', $errors);
    }

    public function testMissingSymbolReportsSymbolError(): void
    {
        $errors = $this->validator->validate('NoSymbols1234');

        self::assertContains('Password must include at least one symbol.', $errors);
    }

    public function testCommonWeakPasswordReturnsAllErrors(): void
    {
        $errors = $this->validator->validate('qwerty');

        self::assertCount(4, $errors);
    }
}
