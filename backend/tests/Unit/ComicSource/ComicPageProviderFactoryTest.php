<?php

namespace App\Tests\Unit\ComicSource;

use App\ComicSource\ComicPageProviderFactory;
use App\ComicSource\PdfPageProvider;
use App\ComicSource\SevenZipPageProvider;
use App\ComicSource\ZipPageProvider;
use App\Enum\ComicSourceType;
use PHPUnit\Framework\TestCase;

final class ComicPageProviderFactoryTest extends TestCase
{
    public function testEverySupportedFormatResolvesToItsProvider(): void
    {
        $zip = new ZipPageProvider(); $sevenZip = new SevenZipPageProvider(); $pdf = new PdfPageProvider();
        $factory = new ComicPageProviderFactory([$zip, $sevenZip, $pdf]);
        self::assertSame($zip, $factory->for(ComicSourceType::CBZ));
        foreach ([ComicSourceType::CBR, ComicSourceType::CB7, ComicSourceType::CBT] as $type) self::assertSame($sevenZip, $factory->for($type));
        self::assertSame($pdf, $factory->for(ComicSourceType::PDF));
    }
}
