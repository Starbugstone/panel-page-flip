<?php

declare(strict_types=1);

namespace App\Reader;

/**
 * The persisted reader contract.
 *
 * Keeping an envelope around the global settings gives later reader work a
 * place for device/orientation overrides without changing the meaning of the
 * existing values. Stored data is always normalized before it reaches a
 * client, while writes are strict so arbitrary JSON is never retained.
 */
final class ReaderPreferences
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const MODES = ['single'];

    /** @var list<string> */
    private const DIRECTIONS = ['ltr'];

    /** @var list<string> */
    private const FITS = ['contain', 'width', 'height', 'original'];

    /**
     * @return array{
     *     schemaVersion: int,
     *     settings: array{
     *         mode: string,
     *         direction: string,
     *         fit: string,
     *         autoHideControls: bool,
     *         showProgress: bool,
     *         wakeLock: bool
     *     },
     *     overrides: array{}
     * }
     */
    public function defaults(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'settings' => [
                'mode' => 'single',
                'direction' => 'ltr',
                'fit' => 'contain',
                'autoHideControls' => true,
                'showProgress' => true,
                'wakeLock' => true,
            ],
            // Reserved for validated device/orientation contexts. Keeping the
            // slot now lets later work extend the model instead of replacing it.
            'overrides' => [],
        ];
    }

    /**
     * Safely consume persisted data written by this or an older application.
     * Invalid fields fall back independently, so one stale value does not erase
     * the rest of a user's preferences.
     *
     * @param array<string, mixed>|null $stored
     * @return array<string, mixed>
     */
    public function normalize(?array $stored): array
    {
        $defaults = $this->defaults();
        if (($stored['schemaVersion'] ?? null) !== self::SCHEMA_VERSION
            || !is_array($stored['settings'] ?? null)) {
            return $defaults;
        }

        $settings = $stored['settings'];

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'settings' => [
                'mode' => $this->allowedString($settings['mode'] ?? null, self::MODES, $defaults['settings']['mode']),
                'direction' => $this->allowedString($settings['direction'] ?? null, self::DIRECTIONS, $defaults['settings']['direction']),
                'fit' => $this->allowedString($settings['fit'] ?? null, self::FITS, $defaults['settings']['fit']),
                'autoHideControls' => is_bool($settings['autoHideControls'] ?? null)
                    ? $settings['autoHideControls']
                    : $defaults['settings']['autoHideControls'],
                'showProgress' => is_bool($settings['showProgress'] ?? null)
                    ? $settings['showProgress']
                    : $defaults['settings']['showProgress'],
                'wakeLock' => is_bool($settings['wakeLock'] ?? null)
                    ? $settings['wakeLock']
                    : $defaults['settings']['wakeLock'],
            ],
            'overrides' => [],
        ];
    }

    /**
     * Validate an API replacement. Unlike normalize(), this rejects malformed,
     * incomplete, unknown, or currently unsupported values.
     *
     * @return array<string, mixed>
     */
    public function validate(mixed $candidate): array
    {
        if (!is_array($candidate)) {
            throw new \InvalidArgumentException('preferences must be an object.');
        }

        $this->assertExactKeys($candidate, ['schemaVersion', 'settings', 'overrides'], 'preferences');

        if (($candidate['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('schemaVersion is not supported.');
        }

        $settings = $candidate['settings'] ?? null;
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('settings must be an object.');
        }

        if (($candidate['overrides'] ?? null) !== []) {
            throw new \InvalidArgumentException('overrides are not supported yet.');
        }

        $required = ['mode', 'direction', 'fit', 'autoHideControls', 'showProgress', 'wakeLock'];
        $this->assertExactKeys($settings, $required, 'settings');

        if (!in_array($settings['mode'], self::MODES, true)) {
            throw new \InvalidArgumentException('mode is not supported.');
        }
        if (!in_array($settings['direction'], self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException('direction is not supported.');
        }
        if (!in_array($settings['fit'], self::FITS, true)) {
            throw new \InvalidArgumentException('fit is not supported.');
        }

        foreach (['autoHideControls', 'showProgress', 'wakeLock'] as $field) {
            if (!is_bool($settings[$field])) {
                throw new \InvalidArgumentException(sprintf('%s must be a boolean.', $field));
            }
        }

        return $this->normalize($candidate);
    }

    /** @param list<string> $allowed */
    private function allowedString(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private function assertExactKeys(array $value, array $expected, string $field): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new \InvalidArgumentException(sprintf('%s has missing or unknown fields.', $field));
        }
    }
}
