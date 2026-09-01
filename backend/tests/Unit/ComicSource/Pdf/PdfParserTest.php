<?php

declare(strict_types=1);

namespace App\Tests\Unit\ComicSource\Pdf;

use App\ComicSource\Pdf\PdfException;
use App\ComicSource\Pdf\PdfParser;
use PHPUnit\Framework\TestCase;

final class PdfParserTest extends TestCase
{
    public function testRejectsAContainerWithAnUnboundedNumberOfValues(): void
    {
        $parser = new PdfParser('['.str_repeat('0 ', 200_001).']');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('too many values');

        $parser->parseValue();
    }

    public function testRejectsAnOversizedLiteralString(): void
    {
        $parser = new PdfParser('('.str_repeat('a', 2_097_153).')');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('string is too large');

        $parser->parseValue();
    }

    public function testRejectsAnOversizedName(): void
    {
        $parser = new PdfParser('/'.str_repeat('a', 1_025));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('name is too large');

        $parser->parseValue();
    }

    public function testRejectsAnOversizedHexStringBeforeCopyingIt(): void
    {
        $parser = new PdfParser('<'.str_repeat('AA', 2_097_153).'>');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('hex string is too large');

        $parser->parseValue();
    }
}
