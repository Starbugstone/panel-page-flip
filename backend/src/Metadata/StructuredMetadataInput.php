<?php

declare(strict_types=1);

namespace App\Metadata;

use App\Entity\Comic;

/**
 * Applies structured metadata sent by a client to a comic.
 *
 * A field is only touched when the payload mentions it, so accepting one
 * suggestion cannot blank the fields the user left alone. Anything malformed is
 * reported rather than quietly dropped: the user pressed a button expecting a
 * value to land, and silence would look like it worked.
 */
final class StructuredMetadataInput
{
    private const MAX_ISSUE_NUMBER_LENGTH = 50;
    private const MAX_AGE_RATING_LENGTH = 32;
    private const EARLIEST_YEAR = 1000;
    private const MAX_CREATOR_ROLES = 30;
    private const MAX_CREATORS_PER_ROLE = 50;
    private const MAX_CREATOR_NAME_LENGTH = 255;

    /** @var list<string> */
    private array $errors = [];

    /** @param list<string> $knownProviders keys a metadata origin may name */
    public function __construct(private readonly array $knownProviders = [])
    {
    }

    /** @param array<string, mixed> $data */
    public function applyTo(array $data, Comic $comic): bool
    {
        $this->errors = [];

        $this->text($data, 'series', 255, static fn (?string $v) => $comic->setSeries($v));
        $this->text($data, 'issueNumber', self::MAX_ISSUE_NUMBER_LENGTH, static fn (?string $v) => $comic->setIssueNumber($v));
        $this->text($data, 'ageRating', self::MAX_AGE_RATING_LENGTH, static fn (?string $v) => $comic->setAgeRating($v));
        $this->positiveInt($data, 'issueCount', static fn (?int $v) => $comic->setIssueCount($v));
        $this->positiveInt($data, 'volume', static fn (?int $v) => $comic->setVolume($v));
        $this->languageCode($data, $comic);
        $this->publishedAt($data, $comic);
        $this->creators($data, $comic);
        $this->metadataOrigin($data, $comic);

        return $this->errors === [];
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(?string): mixed $set
     */
    private function text(array $data, string $field, int $limit, callable $set): void
    {
        if (!array_key_exists($field, $data)) {
            return;
        }

        $value = $data[$field];
        if ($value === null) {
            $set(null);

            return;
        }

        if (!is_string($value)) {
            $this->errors[] = sprintf('%s must be a string or null.', $field);

            return;
        }

        $value = trim($value);
        if (mb_strlen($value) > $limit) {
            $this->errors[] = sprintf('%s is longer than %d characters.', $field, $limit);

            return;
        }

        $set($value === '' ? null : $value);
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(?int): mixed $set
     */
    private function positiveInt(array $data, string $field, callable $set): void
    {
        if (!array_key_exists($field, $data)) {
            return;
        }

        $value = $data[$field];
        if ($value === null || $value === '') {
            $set(null);

            return;
        }

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            $this->errors[] = sprintf('%s must be a whole number or null.', $field);

            return;
        }

        $number = (int) $value;
        if ($number < 1) {
            $this->errors[] = sprintf('%s must be greater than zero.', $field);

            return;
        }

        $set($number);
    }

    /**
     * Structured credits, as role => names.
     *
     * Accepted from the client because the review UI is where a provider's
     * credits are approved, and rejected rather than trimmed when malformed:
     * silently keeping half of somebody's accepted credit list is worse than
     * telling them it did not go through.
     *
     * @param array<string, mixed> $data
     */
    private function creators(array $data, Comic $comic): void
    {
        if (!array_key_exists('creators', $data)) {
            return;
        }

        $value = $data['creators'];
        if ($value === null || $value === []) {
            $comic->setCreators([]);

            return;
        }

        if (!is_array($value) || array_is_list($value)) {
            $this->errors[] = 'creators must be an object of role names to lists of people.';

            return;
        }

        if (count($value) > self::MAX_CREATOR_ROLES) {
            $this->errors[] = sprintf('creators cannot have more than %d roles.', self::MAX_CREATOR_ROLES);

            return;
        }

        $creators = [];

        foreach ($value as $role => $names) {
            $role = trim((string) $role);
            if ($role === '' || mb_strlen($role) > self::MAX_CREATOR_NAME_LENGTH) {
                $this->errors[] = 'creators has a role name that is empty or too long.';

                return;
            }

            if (!is_array($names) || !array_is_list($names) || count($names) > self::MAX_CREATORS_PER_ROLE) {
                $this->errors[] = sprintf('creators.%s must be a list of at most %d names.', $role, self::MAX_CREATORS_PER_ROLE);

                return;
            }

            $people = [];
            foreach ($names as $name) {
                if (!is_string($name) || mb_strlen($name) > self::MAX_CREATOR_NAME_LENGTH) {
                    $this->errors[] = sprintf('creators.%s must contain only names.', $role);

                    return;
                }

                $name = trim($name);
                if ($name !== '' && !in_array($name, $people, true)) {
                    $people[] = $name;
                }
            }

            if ($people !== []) {
                $creators[mb_strtolower($role)] = $people;
            }
        }

        $comic->setCreators($creators);
    }

    /**
     * Which external record this comic was matched to.
     *
     * Recorded so a later refresh can ask for that exact record rather than
     * repeating a fuzzy search. The provider has to be one this server actually
     * has, because the value reaches a URL path on the way back out.
     *
     * @param array<string, mixed> $data
     */
    private function metadataOrigin(array $data, Comic $comic): void
    {
        if (!array_key_exists('metadataProvider', $data) && !array_key_exists('metadataExternalId', $data)) {
            return;
        }

        $provider = $data['metadataProvider'] ?? null;
        $externalId = $data['metadataExternalId'] ?? null;

        if ($provider === null || $provider === '' || $externalId === null || $externalId === '') {
            $comic->setMetadataOrigin(null, null);

            return;
        }

        if (!is_string($provider) || !in_array($provider, $this->knownProviders, true)) {
            $this->errors[] = 'metadataProvider is not a provider this server knows.';

            return;
        }

        if ((!is_string($externalId) && !is_int($externalId)) || preg_match('/^[A-Za-z0-9\-_]{1,64}$/', (string) $externalId) !== 1) {
            $this->errors[] = 'metadataExternalId is not a usable record reference.';

            return;
        }

        $comic->setMetadataOrigin($provider, (string) $externalId);
    }

    /** @param array<string, mixed> $data */
    private function languageCode(array $data, Comic $comic): void
    {
        if (!array_key_exists('languageCode', $data)) {
            return;
        }

        $value = $data['languageCode'];
        if ($value === null || $value === '') {
            $comic->setLanguageCode(null);

            return;
        }

        if (!is_string($value) || preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $value) !== 1) {
            $this->errors[] = 'languageCode must be an ISO language code.';

            return;
        }

        $comic->setLanguageCode(strtolower($value));
    }

    /** @param array<string, mixed> $data */
    private function publishedAt(array $data, Comic $comic): void
    {
        if (!array_key_exists('publishedAt', $data)) {
            return;
        }

        $value = $data['publishedAt'];
        if ($value === null || $value === '') {
            $comic->setPublishedAt(null);

            return;
        }

        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < self::EARLIEST_YEAR) {
            $this->errors[] = 'publishedAt must be a date in Y-m-d form.';

            return;
        }

        $comic->setPublishedAt($date);
    }
}
