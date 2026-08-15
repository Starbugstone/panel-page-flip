<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AppDataEncryptionService;
use PHPUnit\Framework\TestCase;

final class AppDataEncryptionServiceTest extends TestCase
{
    private AppDataEncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new AppDataEncryptionService(str_repeat('ab', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testEncryptsAndDecryptsSecrets(): void
    {
        $encrypted = $this->encryption->encrypt('dropbox-refresh-token');
        self::assertNotSame('dropbox-refresh-token', $encrypted);
        self::assertTrue($this->encryption->isEncrypted($encrypted));
        self::assertSame('dropbox-refresh-token', $this->encryption->decrypt($encrypted));
    }

    public function testEncryptionIsNonDeterministic(): void
    {
        self::assertNotSame($this->encryption->encrypt('same-secret'), $this->encryption->encrypt('same-secret'));
    }

    public function testAlreadyEncryptedValueIsNotEncryptedAgain(): void
    {
        $encrypted = $this->encryption->encrypt('token');
        self::assertSame($encrypted, $this->encryption->encrypt($encrypted));
    }

    /** @dataProvider passthroughValues */
    public function testEmptyValuesPassThrough(?string $value): void
    {
        self::assertSame($value, $this->encryption->encrypt($value));
        self::assertSame($value, $this->encryption->decrypt($value));
    }

    public function passthroughValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    public function testLegacyPlaintextCanStillBeRead(): void
    {
        self::assertSame('legacy-token', $this->encryption->decrypt('legacy-token'));
    }

    public function testRejectsMalformedCiphertext(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt('enc:v1:not-valid-base64');
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $encrypted = (string) $this->encryption->encrypt('sensitive');
        $tampered = substr($encrypted, 0, -2) . 'AA';
        $this->expectException(\RuntimeException::class);
        $this->encryption->decrypt($tampered);
    }

    public function testAcceptsHexAndBase64EncodedKeys(): void
    {
        $rawKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        foreach ([bin2hex($rawKey), base64_encode($rawKey)] as $encodedKey) {
            $service = new AppDataEncryptionService($encodedKey);
            self::assertSame('value', $service->decrypt($service->encrypt('value')));
        }
    }

    /** @dataProvider invalidKeys */
    public function testRejectsArbitraryOrMalformedKeys(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppDataEncryptionService($key);
    }

    public function invalidKeys(): iterable
    {
        yield 'human passphrase' => ['ChangeMeInEnvLocal'];
        yield 'short hex' => ['deadbeef'];
        yield 'wrong base64 length' => [base64_encode('too-short')];
        yield 'empty' => [''];
    }
}
