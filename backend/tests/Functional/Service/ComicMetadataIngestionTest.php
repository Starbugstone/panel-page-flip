<?php

namespace App\Tests\Functional\Service;

use App\Enum\ComicSourceType;
use App\Enum\ReadingDirection;
use App\Service\ComicService;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Importing a comic reads what it says about itself, and an upload never
 * depends on that succeeding.
 */
final class ComicMetadataIngestionTest extends AbstractApiTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    public function testStoresTheMetadataTheComicCarries(): void
    {
        $comic = $this->upload($this->comicInfo(<<<'XML'
            <Series>Batman</Series>
            <Number>7</Number>
            <Count>13</Count>
            <Volume>1996</Volume>
            <Publisher>DC</Publisher>
            <Summary>A killer strikes on holidays.</Summary>
            <Year>1997</Year><Month>4</Month><Day>9</Day>
            <LanguageISO>en</LanguageISO>
            <AgeRating>Teen</AgeRating>
            <Manga>YesAndRightToLeft</Manga>
            <Writer>Jeph Loeb</Writer>
            <Pages>
              <Page Image="0" Type="FrontCover" ImageWidth="1200" ImageHeight="1800" />
              <Page Image="1" DoublePage="true" />
            </Pages>
        XML));

        self::assertSame('Batman', $comic->getSeries());
        self::assertSame('7', $comic->getIssueNumber());
        self::assertSame(13, $comic->getIssueCount());
        self::assertSame(1996, $comic->getVolume());
        self::assertSame('1997-04-09', $comic->getPublishedAt()?->format('Y-m-d'));
        self::assertSame('en', $comic->getLanguageCode());
        self::assertSame('Teen', $comic->getAgeRating());
        self::assertSame(ReadingDirection::RightToLeft, $comic->getReadingDirection());
        self::assertSame(['writer' => ['Jeph Loeb']], $comic->getCreators());

        $pages = $comic->getPageInfo();
        self::assertSame(1200, $pages[1]->width);
        self::assertTrue($pages[2]->doublePage);
    }

    /**
     * The uploader was looking at the comic; whoever packaged it was not. Their
     * answers are not overwritten by the file's.
     */
    public function testDoesNotOverwriteWhatTheUploaderTyped(): void
    {
        $comic = $this->upload(
            $this->comicInfo('<Series>Batman</Series><Publisher>DC</Publisher><Summary>From the file.</Summary><Writer>Jeph Loeb</Writer>'),
            publisher: 'Typed Publisher',
            description: 'Typed description.',
            author: 'Typed Author',
        );

        self::assertSame('Typed Publisher', $comic->getPublisher());
        self::assertSame('Typed description.', $comic->getDescription());
        self::assertSame('Typed Author', $comic->getAuthor());
        self::assertSame('Batman', $comic->getSeries());
    }

    public function testFillsOnlyTheGapsTheUploaderLeft(): void
    {
        $comic = $this->upload(
            $this->comicInfo('<Publisher>DC</Publisher><Summary>From the file.</Summary><Writer>Jeph Loeb, Tim Sale</Writer>')
        );

        self::assertSame('DC', $comic->getPublisher());
        self::assertSame('From the file.', $comic->getDescription());
        self::assertSame('Jeph Loeb, Tim Sale', $comic->getAuthor());
    }

    /** @dataProvider unusableMetadata */
    public function testImportsNormallyWhenTheMetadataIsUnusable(?string $comicInfo): void
    {
        $comic = $this->upload($comicInfo);

        self::assertNotNull($comic->getId());
        self::assertSame(2, $comic->getPageCount());
        self::assertNull($comic->getSeries());
        self::assertSame([], $comic->getPageInfo());
        self::assertSame(ReadingDirection::LeftToRight, $comic->getReadingDirection());
    }

    public function unusableMetadata(): iterable
    {
        yield 'absent' => [null];
        yield 'not xml' => ['this is not xml'];
        yield 'wrong root' => ['<Nope><Series>Batman</Series></Nope>'];
        yield 'hostile doctype' => ['<!DOCTYPE ComicInfo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><ComicInfo><Series>&xxe;</Series></ComicInfo>'];
    }

    public function testExposesTheMetadataThroughTheApi(): void
    {
        $owner = UserFactory::createOne();
        $comic = $this->upload($this->comicInfo('<Series>Batman</Series><Number>7</Number><Manga>YesAndRightToLeft</Manga>'), owner: $owner);

        $this->loginAs($owner);
        $this->browser()->request('GET', sprintf('/api/comics/%d', $comic->getId()));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->browser()->getResponse()->getContent(), true);

        self::assertSame('Batman', $payload['comic']['series']);
        self::assertSame('7', $payload['comic']['issueNumber']);
        self::assertSame('rtl', $payload['comic']['readingDirection']);
    }

    private function upload(
        ?string $comicInfo,
        ?object $owner = null,
        ?string $author = null,
        ?string $publisher = null,
        ?string $description = null,
    ): \App\Entity\Comic {
        $owner ??= UserFactory::createOne();

        $path = tempnam(sys_get_temp_dir(), 'comic-ingest-').'.cbz';
        $this->temporaryFiles[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('page-01.jpg', $this->onePixelJpeg());
        $zip->addFromString('page-02.jpg', $this->onePixelJpeg()."\x00");
        if ($comicInfo !== null) {
            $zip->addFromString('ComicInfo.xml', $comicInfo);
        }
        $zip->close();

        $comic = self::getContainer()->get(ComicService::class)->uploadComic(
            new UploadedFile($path, 'batman-007.cbz', null, null, true),
            $owner,
            'Batman 007',
            $author,
            $publisher,
            $description,
        );

        $stored = self::getContainer()->getParameter('comics_directory').'/'.$owner->getId().'/'.$comic->getFilePath();
        $this->temporaryFiles[] = $stored;
        self::assertSame(ComicSourceType::CBZ, $comic->getSourceType());

        return $comic;
    }

    private function comicInfo(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><ComicInfo>'.$body.'</ComicInfo>';
    }

    private function onePixelJpeg(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
            .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
            .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
            true
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) @unlink($file);
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }
}
