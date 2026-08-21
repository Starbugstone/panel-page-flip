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
        self::assertSame('rtl', $normalized['settings']['direction']);
        self::assertSame('width', $normalized['settings']['fit']);
        self::assertFalse($normalized['settings']['autoHideControls']);
        self::assertTrue($normalized['settings']['showProgress']);
        self::assertTrue($normalized['settings']['wakeLock']);
    }

    public function testKeepsOverridesItUnderstandsAndDropsTheRest(): void
    {
        $normalized = $this->preferences->normalize([
            'schemaVersion' => 1,
            'settings' => $this->preferences->defaults()['settings'],
            'overrides' => [
                ['context' => ['device' => 'watch', 'orientation' => 'portrait'], 'settings' => ['fit' => 'width']],
                ['context' => ['device' => 'phone', 'orientation' => 'sideways'], 'settings' => ['fit' => 'width']],
                ['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'stretch']],
                ['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'width']],
                'not an override',
            ],
        ]);

        self::assertSame([
            ['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'width']],
        ], $normalized['overrides']);
    }

    public function testLastWrittenContextWins(): void
    {
        $normalized = $this->preferences->normalize([
            'schemaVersion' => 1,
            'settings' => $this->preferences->defaults()['settings'],
            'overrides' => [
                ['context' => ['device' => 'tablet', 'orientation' => 'landscape'], 'settings' => ['fit' => 'width']],
                ['context' => ['device' => 'tablet', 'orientation' => 'landscape'], 'settings' => ['fit' => 'height']],
            ],
        ]);

        self::assertCount(1, $normalized['overrides']);
        self::assertSame('height', $normalized['overrides'][0]['settings']['fit']);
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
        $candidate['settings']['mode'] = 'continuous';
        $candidate['settings']['direction'] = 'rtl';
        $candidate['settings']['fit'] = 'original';
        $candidate['settings']['showProgress'] = false;
        $candidate['dismissedSuggestions'] = ['fit:phone:portrait', 'mode:tablet:landscape'];

        self::assertSame($candidate, $this->preferences->validate($candidate));
    }

    public function testValidOverridesAreAccepted(): void
    {
        $candidate = $this->preferences->defaults();
        $candidate['overrides'] = [
            ['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'width']],
            ['context' => ['device' => 'tablet', 'orientation' => 'landscape'], 'settings' => ['fit' => 'contain']],
        ];

        self::assertSame($candidate, $this->preferences->validate($candidate));
    }

    /**
     * A context says how a page is sized on that shape of screen. Letting it
     * carry a mode or a direction would be a way to select a renderer that the
     * global settings currently refuse.
     */
    public function testOverrideCannotCarryASettingItIsNotAllowedTo(): void
    {
        $candidate = $this->preferences->defaults();
        $candidate['overrides'] = [[
            'context' => ['device' => 'phone', 'orientation' => 'portrait'],
            'settings' => ['fit' => 'width', 'mode' => 'double'],
        ]];

        $this->expectException(\InvalidArgumentException::class);
        $this->preferences->validate($candidate);
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
            ...$defaults,
            'settings' => [...$defaults['settings'], 'mode' => 'holographic'],
        ]];
        yield 'duplicate dismissed suggestion' => [[
            ...$defaults,
            'dismissedSuggestions' => ['fit:phone:portrait', 'fit:phone:portrait'],
        ]];
        yield 'invalid dismissed suggestion' => [[
            ...$defaults,
            'dismissedSuggestions' => [''],
        ]];
        yield 'wrong boolean type' => [[
            'schemaVersion' => 1,
            'settings' => [
                ...$defaults['settings'],
                'wakeLock' => 1,
            ],
        ]];
        yield 'overrides not a list' => [[
            ...$defaults,
            'overrides' => ['phone:portrait' => ['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'width']]],
        ]];
        yield 'unknown override device' => [[
            ...$defaults,
            'overrides' => [['context' => ['device' => 'watch', 'orientation' => 'portrait'], 'settings' => ['fit' => 'width']]],
        ]];
        yield 'unknown override orientation' => [[
            ...$defaults,
            'overrides' => [['context' => ['device' => 'phone', 'orientation' => 'sideways'], 'settings' => ['fit' => 'width']]],
        ]];
        yield 'unsupported override fit' => [[
            ...$defaults,
            'overrides' => [['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'stretch']]],
        ]];
        yield 'the same context twice' => [[
            ...$defaults,
            'overrides' => [
                ['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'width']],
                ['context' => ['device' => 'phone', 'orientation' => 'portrait'], 'settings' => ['fit' => 'height']],
            ],
        ]];
        yield 'more contexts than exist' => [[
            ...$defaults,
            'overrides' => array_fill(0, 7, [
                'context' => ['device' => 'phone', 'orientation' => 'portrait'],
                'settings' => ['fit' => 'width'],
            ]),
        ]];
        yield 'override missing its context' => [[
            ...$defaults,
            'overrides' => [['settings' => ['fit' => 'width']]],
        ]];
    }
}
