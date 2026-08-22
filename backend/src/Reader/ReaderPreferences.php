<?php

declare(strict_types=1);

namespace App\Reader;

/**
 * The persisted reader contract.
 *
 * The envelope around the global settings carries per-device/orientation
 * overrides, so a phone held upright can be read differently from a tablet
 * turned sideways without either rewriting the account default. Stored data is
 * always normalized before it reaches a client, while writes are strict so
 * arbitrary JSON is never retained.
 */
final class ReaderPreferences
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const MODES = ['single', 'double', 'continuous'];

    /** @var list<string> */
    private const DIRECTIONS = ['ltr', 'rtl'];

    /** @var list<string> */
    private const FITS = ['contain', 'width', 'height', 'original'];

    /** @var list<string> */
    private const DEVICES = ['phone', 'tablet', 'desktop'];

    /** @var list<string> */
    private const ORIENTATIONS = ['portrait', 'landscape'];

    /**
     * What a device/orientation context may say for itself. A context chooses
     * how a page is sized on that shape of screen; selecting a renderer stays
     * global, so mode and direction are deliberately absent.
     *
     * @var list<string>
     */
    private const OVERRIDABLE = ['fit'];

    /**
     * @return array{
     *     schemaVersion: int,
     *     settings: array{
     *         mode: string,
     *         direction: string,
     *         fit: string,
     *         autoHideControls: bool,
     *         showProgress: bool,
     *         wakeLock: bool,
     *         coverAlone: bool
     *     },
     *     overrides: list<array{context: array{device: string, orientation: string}, settings: array{fit: string}}>,
     *     dismissedSuggestions: list<string>
     * }
     */
    public function defaults(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'settings' => [
                // Continuous scroll, because it is the reading model every
                // other thing on a phone already uses and the only one with no
                // page-turn target to miss. Paged reading stays one setting
                // away for anybody who prefers it.
                'mode' => 'continuous',
                'direction' => 'ltr',
                'fit' => 'contain',
                'autoHideControls' => true,
                'showProgress' => true,
                'wakeLock' => true,
                'coverAlone' => true,
            ],
            // One entry per device/orientation the reader has been told about;
            // an account that has never said anything has none.
            'overrides' => [],
            'dismissedSuggestions' => [],
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
                'coverAlone' => is_bool($settings['coverAlone'] ?? null)
                    ? $settings['coverAlone']
                    : $defaults['settings']['coverAlone'],
            ],
            'overrides' => $this->normalizeOverrides($stored['overrides'] ?? null),
            'dismissedSuggestions' => $this->normalizeDismissedSuggestions($stored['dismissedSuggestions'] ?? null),
        ];
    }

    /** @return list<string> */
    private function normalizeDismissedSuggestions(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $valid = array_filter($stored, static fn (mixed $value): bool => is_string($value) && $value !== '' && strlen($value) <= 80);

        return array_slice(array_values(array_unique($valid)), 0, 24);
    }

    /**
     * Keep what still makes sense and drop the rest, so one context written by
     * a newer client cannot cost a user the settings they made everywhere else.
     *
     * @return list<array{context: array{device: string, orientation: string}, settings: array{fit: string}}>
     */
    private function normalizeOverrides(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $byContext = [];
        foreach ($stored as $override) {
            $device = $override['context']['device'] ?? null;
            $orientation = $override['context']['orientation'] ?? null;
            $fit = $override['settings']['fit'] ?? null;

            if (!is_string($device) || !in_array($device, self::DEVICES, true)
                || !is_string($orientation) || !in_array($orientation, self::ORIENTATIONS, true)
                || !is_string($fit) || !in_array($fit, self::FITS, true)) {
                continue;
            }

            // Last wins, so a context saved twice reads as one choice rather
            // than two entries that disagree.
            $byContext[$device . ':' . $orientation] = [
                'context' => ['device' => $device, 'orientation' => $orientation],
                'settings' => ['fit' => $fit],
            ];
        }

        return array_values(array_slice($byContext, 0, self::maxOverrides()));
    }

    private static function maxOverrides(): int
    {
        return count(self::DEVICES) * count(self::ORIENTATIONS);
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

        $this->assertExactKeys($candidate, ['schemaVersion', 'settings', 'overrides', 'dismissedSuggestions'], 'preferences');

        if (($candidate['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('schemaVersion is not supported.');
        }

        $settings = $candidate['settings'] ?? null;
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('settings must be an object.');
        }

        $this->assertValidOverrides($candidate['overrides'] ?? null);
        $dismissedSuggestions = $candidate['dismissedSuggestions'] ?? null;
        if (!is_array($dismissedSuggestions) || !array_is_list($dismissedSuggestions)
            || count($dismissedSuggestions) > 24
            || count(array_unique($dismissedSuggestions, SORT_REGULAR)) !== count($dismissedSuggestions)) {
            throw new \InvalidArgumentException('dismissedSuggestions must be a unique bounded list.');
        }
        foreach ($dismissedSuggestions as $suggestion) {
            if (!is_string($suggestion) || $suggestion === '' || strlen($suggestion) > 80) {
                throw new \InvalidArgumentException('dismissed suggestion is invalid.');
            }
        }

        $required = ['mode', 'direction', 'fit', 'autoHideControls', 'showProgress', 'wakeLock', 'coverAlone'];
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

        foreach (['autoHideControls', 'showProgress', 'wakeLock', 'coverAlone'] as $field) {
            if (!is_bool($settings[$field])) {
                throw new \InvalidArgumentException(sprintf('%s must be a boolean.', $field));
            }
        }

        return $this->normalize($candidate);
    }

    /**
     * Unlike normalize(), a write is refused rather than trimmed: a client
     * sending a context this application does not have should be told so, not
     * have part of its request silently dropped.
     */
    private function assertValidOverrides(mixed $overrides): void
    {
        if (!is_array($overrides) || !array_is_list($overrides)) {
            throw new \InvalidArgumentException('overrides must be a list.');
        }

        // The set of contexts is closed, so a longer list is duplicates or junk
        // either way; refusing it keeps an unbounded write out of the column.
        if (count($overrides) > self::maxOverrides()) {
            throw new \InvalidArgumentException('too many overrides.');
        }

        $seen = [];
        foreach ($overrides as $override) {
            if (!is_array($override)) {
                throw new \InvalidArgumentException('each override must be an object.');
            }

            $this->assertExactKeys($override, ['context', 'settings'], 'override');

            $context = $override['context'];
            if (!is_array($context)) {
                throw new \InvalidArgumentException('override context must be an object.');
            }
            $this->assertExactKeys($context, ['device', 'orientation'], 'override context');

            if (!in_array($context['device'], self::DEVICES, true)) {
                throw new \InvalidArgumentException('override device is not supported.');
            }
            if (!in_array($context['orientation'], self::ORIENTATIONS, true)) {
                throw new \InvalidArgumentException('override orientation is not supported.');
            }

            $key = $context['device'] . ':' . $context['orientation'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('overrides name the same context twice.');
            }
            $seen[$key] = true;

            $settings = $override['settings'];
            if (!is_array($settings)) {
                throw new \InvalidArgumentException('override settings must be an object.');
            }
            $this->assertExactKeys($settings, self::OVERRIDABLE, 'override settings');

            if (!in_array($settings['fit'], self::FITS, true)) {
                throw new \InvalidArgumentException('override fit is not supported.');
            }
        }
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
