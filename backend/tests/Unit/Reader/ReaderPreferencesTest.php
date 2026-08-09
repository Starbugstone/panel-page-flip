<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reader;

use App\Reader\ReaderPreferences;
use PHPUnit\Framework\TestCase;

final class ReaderPreferencesTest extends TestCase
{
    private ReaderPreferences $preferences;

    protected function setUp(): void
    {
        $this->preferences = new ReaderPreferences();
    }

    public function testNormalizesInvalidStoredFieldsWithoutDiscardingValidOnes(): void
    {
        $normalized = $this->preferences->normalize([
            'schemaVersion' => 1,
            'settings' => [
                'mode' => 'future-mode',
                'direction' => 'rtl',
                'fit' => 'width',
                'autoHideControls' => false,
                'showProgress' => 'yes',
                'wakeLock' => true,
                'unknown' => 'ignored',
            ],
        ]);

        self::assertSame('single', $normalized['settings']['mode']);
        self::assertSame('ltr', $normalized['settings']['direction']);
        self::assertSame('width', $normalized['settings']['fit']);
        self::assertFalse($normalized['settings']['autoHideControls']);
        self::assertTrue($normalized['settings']['showProgress']);
        self::assertTrue($normalized['settings']['wakeLock']);
    }

    public function testStaleSchemaFallsBackToAllDefaults(): void
    {
        self::assertSame(
            $this->preferences->defaults(),
            $this->preferences->normalize(['schemaVersion' => 99, 'settings' => ['fit' => 'width']]),
        );
    }

    public function testValidReplacementIsAccepted(): void
    {
        $candidate = $this->preferences->defaults();
        $candidate['settings']['fit'] = 'original';
        $candidate['settings']['showProgress'] = false;

        self::assertSame($candidate, $this->preferences->validate($candidate));
    }

    /** @dataProvider invalidReplacementProvider */
    public function testInvalidOrArbitraryReplacementIsRejected(mixed $candidate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->preferences->validate($candidate);
    }

    /** @return iterable<string, array{mixed}> */
    public function invalidReplacementProvider(): iterable
    {
        $defaults = (new ReaderPreferences())->defaults();

        yield 'not an object' => ['contain'];
        yield 'unknown root field' => [[
            ...$defaults,
            'untrusted' => ['anything'],
        ]];
        yield 'unsupported mode' => [[
            'schemaVersion' => 1,
            'settings' => [
                ...$defaults['settings'],
                'mode' => 'double',
            ],
        ]];
        yield 'wrong boolean type' => [[
            'schemaVersion' => 1,
            'settings' => [
                ...$defaults['settings'],
                'wakeLock' => 1,
            ],
        ]];
    }
}
