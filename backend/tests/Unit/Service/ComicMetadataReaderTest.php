<?php

namespace App\Tests\Unit\Service;

use App\ComicSource\ComicInfoSourceFactory;
use App\ComicSource\ComicInfoSourceInterface;
use App\Enum\ComicSourceType;
use App\Metadata\ComicInfoParser;
use App\Service\ComicMetadataReader;
use PHPUnit\Framework\TestCase;

final class ComicMetadataReaderTest extends TestCase
{
    public function testReadsAndParsesWhatTheSourceProvides(): void
    {
        $reader = $this->reader($this->source(ComicSourceType::CBZ, '<ComicInfo><Series>Saga</Series></ComicInfo>'));

        self::assertSame('Saga', $reader->read('/comic.cbz', ComicSourceType::CBZ)?->series);
    }

    public function testReturnsNullForAFormatWithNoSource(): void
    {
        $reader = $this->reader($this->source(ComicSourceType::CBZ, '<ComicInfo><Series>Saga</Series></ComicInfo>'));

        self::assertNull($reader->read('/comic.pdf', ComicSourceType::PDF));
    }

    public function testReturnsNullWhenTheSourceHasNoMetadata(): void
    {
        $reader = $this->reader($this->source(ComicSourceType::CBZ, null));

        self::assertNull($reader->read('/comic.cbz', ComicSourceType::CBZ));
    }

    public function testReturnsNullWhenTheDocumentIsUnusable(): void
    {
        $reader = $this->reader($this->source(ComicSourceType::CBZ, 'not xml'));

        self::assertNull($reader->read('/comic.cbz', ComicSourceType::CBZ));
    }

    /**
     * Metadata never decides whether a comic imports, so a source that throws
     * has to be absorbed rather than propagated.
     */
    public function testSwallowsAFailingSource(): void
    {
        $throwing = new class implements ComicInfoSourceInterface {
            public function supports(ComicSourceType $type): bool { return true; }
            public function readComicInfoXml(string $sourcePath, ComicSourceType $type): ?string
            {
                throw new \RuntimeException('archive exploded');
            }
        };

        self::assertNull($this->reader($throwing)->read('/comic.cbz', ComicSourceType::CBZ));
    }

    private function reader(ComicInfoSourceInterface $source): ComicMetadataReader
    {
        return new ComicMetadataReader(new ComicInfoSourceFactory([$source]), new ComicInfoParser());
    }

    private function source(ComicSourceType $supported, ?string $xml): ComicInfoSourceInterface
    {
        return new class($supported, $xml) implements ComicInfoSourceInterface {
            public function __construct(private ComicSourceType $supported, private ?string $xml)
            {
            }

            public function supports(ComicSourceType $type): bool
            {
                return $type === $this->supported;
            }

            public function readComicInfoXml(string $sourcePath, ComicSourceType $type): ?string
            {
                return $this->xml;
            }
        };
    }
}
