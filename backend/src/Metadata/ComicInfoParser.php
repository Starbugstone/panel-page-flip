<?php

declare(strict_types=1);

namespace App\Metadata;

use App\Enum\ComicPageType;
use App\Enum\ReadingDirection;

/**
 * Reads ComicInfo.xml, which arrives inside an uploaded file and is therefore
 * hostile until proven otherwise.
 *
 * Entity substitution and DTD loading stay off, so a document cannot reach the
 * filesystem or the network, and cannot expand itself into exhausting memory.
 * Every value is bounded and range-checked; one unusable field never discards
 * the rest of the document.
 */
final class ComicInfoParser
{
    public const ENTRY_NAME = 'ComicInfo.xml';

    private const MAX_DOCUMENT_BYTES = 2_097_152;
    private const MAX_TEXT_LENGTH = 2_000;
    private const MAX_SUMMARY_LENGTH = 20_000;
    private const MAX_PAGES = 20_000;
    private const MAX_CREATORS_PER_ROLE = 50;

    private const CREATOR_ROLES = [
        'Writer' => 'writer',
        'Penciller' => 'penciller',
        'Inker' => 'inker',
        'Colorist' => 'colorist',
        'Letterer' => 'letterer',
        'CoverArtist' => 'coverArtist',
        'Editor' => 'editor',
    ];

    public function parse(string $xml): ?ComicInfo
    {
        if ($xml === '' || strlen($xml) > self::MAX_DOCUMENT_BYTES) {
            return null;
        }

        $root = $this->rootElement($xml);
        if ($root === null) {
            return null;
        }

        $info = new ComicInfo(
            title: $this->text($root, 'Title'),
            series: $this->text($root, 'Series'),
            issueNumber: $this->text($root, 'Number'),
            issueCount: $this->positiveInt($root, 'Count'),
            volume: $this->positiveInt($root, 'Volume'),
            publisher: $this->text($root, 'Publisher'),
            summary: $this->text($root, 'Summary', self::MAX_SUMMARY_LENGTH),
            publishedAt: $this->publishedAt($root),
            languageCode: $this->languageCode($root),
            ageRating: $this->text($root, 'AgeRating'),
            readingDirection: ReadingDirection::fromManga($this->text($root, 'Manga')),
            creators: $this->creators($root),
            pages: $this->pages($root),
        );

        return $info->isEmpty() ? null : $info;
    }

    private function rootElement(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET blocks network fetches; without NOENT (the default)
            // entities are not substituted, so a declared external entity
            // resolves to nothing rather than to a file's contents.
            $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($root === false || strcasecmp($root->getName(), 'ComicInfo') !== 0) {
            return null;
        }

        return $root;
    }

    private function text(\SimpleXMLElement $root, string $field, int $limit = self::MAX_TEXT_LENGTH): ?string
    {
        if (!isset($root->{$field})) {
            return null;
        }

        $value = trim((string) $root->{$field});

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function positiveInt(\SimpleXMLElement $root, string $field): ?int
    {
        $value = $this->text($root, $field);

        return $value !== null && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** ISO 639 codes only, so a free-text language never reaches the column. */
    private function languageCode(\SimpleXMLElement $root): ?string
    {
        $value = $this->text($root, 'LanguageISO');

        return $value !== null && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $value) === 1
            ? strtolower($value)
            : null;
    }

    private function publishedAt(\SimpleXMLElement $root): ?\DateTimeImmutable
    {
        $year = $this->positiveInt($root, 'Year');
        if ($year === null || $year < 1000 || $year > 9999) {
            return null;
        }

        $month = min(12, max(1, $this->positiveInt($root, 'Month') ?? 1));
        $day = min(31, max(1, $this->positiveInt($root, 'Day') ?? 1));

        return \DateTimeImmutable::createFromFormat(
            '!Y-n-j',
            sprintf('%d-%d-%d', $year, $month, $day)
        ) ?: null;
    }

    /** @return array<string, list<string>> */
    private function creators(\SimpleXMLElement $root): array
    {
        $creators = [];

        foreach (self::CREATOR_ROLES as $field => $role) {
            $names = $this->splitNames($this->text($root, $field));
            if ($names !== []) {
                $creators[$role] = $names;
            }
        }

        return $creators;
    }

    /** @return list<string> */
    private function splitNames(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $names = array_filter(array_map('trim', explode(',', $value)), static fn (string $n): bool => $n !== '');

        return array_values(array_slice(array_unique($names), 0, self::MAX_CREATORS_PER_ROLE));
    }

    /** @return list<ComicPageInfo> */
    private function pages(\SimpleXMLElement $root): array
    {
        if (!isset($root->Pages->Page)) {
            return [];
        }

        $pages = [];

        foreach ($root->Pages->Page as $element) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }

            // ComicInfo numbers pages from zero; everything else here is 1-based.
            $image = $this->attributeInt($element, 'Image');
            if ($image === null || $image < 0) {
                continue;
            }

            $pages[] = new ComicPageInfo(
                page: $image + 1,
                type: ComicPageType::tryFromName($this->attribute($element, 'Type')),
                doublePage: strcasecmp($this->attribute($element, 'DoublePage') ?? '', 'true') === 0,
                width: $this->positiveAttribute($element, 'ImageWidth'),
                height: $this->positiveAttribute($element, 'ImageHeight'),
            );
        }

        usort($pages, static fn (ComicPageInfo $a, ComicPageInfo $b): int => $a->page <=> $b->page);

        return $pages;
    }

    private function attribute(\SimpleXMLElement $element, string $name): ?string
    {
        $value = $element[$name];

        return $value === null ? null : mb_substr(trim((string) $value), 0, self::MAX_TEXT_LENGTH);
    }

    private function attributeInt(\SimpleXMLElement $element, string $name): ?int
    {
        $value = $this->attribute($element, $name);

        return $value !== null && preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function positiveAttribute(\SimpleXMLElement $element, string $name): ?int
    {
        $value = $this->attributeInt($element, $name);

        return $value !== null && $value > 0 ? $value : null;
    }
}
