<?php
// Builds a CBZ and an image-based PDF into /tmp/fixtures inside the php container.
// The PDF mirrors the shape PdfDocumentTest builds: one full-page embedded JPEG
// per page, which is the native (no-Poppler) read path.

$dir = '/tmp/fixtures';
@mkdir($dir, 0777, true);

function pageJpeg(int $n): string
{
    $img = imagecreatetruecolor(600, 900);
    $bg = imagecolorallocate($img, 30 + $n * 40, 60, 140);
    imagefilledrectangle($img, 0, 0, 600, 900, $bg);
    $fg = imagecolorallocate($img, 255, 255, 255);
    // Big, unmistakable page number so a screenshot proves which page is shown.
    imagestring($img, 5, 40, 40, "PAGE $n", $fg);
    for ($i = 1; $i <= $n; $i++) {
        imagefilledrectangle($img, 40, 100 + $i * 60, 40 + $i * 90, 140 + $i * 60, $fg);
    }
    ob_start();
    imagejpeg($img, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);
    return $bytes;
}

$pages = [1 => pageJpeg(1), 2 => pageJpeg(2), 3 => pageJpeg(3)];

// ---- CBZ ----
$cbz = $dir . '/Navigator Test CBZ.cbz';
@unlink($cbz);
$zip = new ZipArchive();
if ($zip->open($cbz, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "cannot create cbz\n");
    exit(1);
}
foreach ($pages as $n => $bytes) {
    $zip->addFromString(sprintf('page-%02d.jpg', $n), $bytes);
}
$zip->close();

// ---- CBZ carrying ComicInfo.xml, for the metadata paths ----
$comicInfo = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ComicInfo>
  <Series>Navigator Chronicles</Series>
  <Number>7</Number>
  <Count>13</Count>
  <Volume>1996</Volume>
  <Publisher>Fixture Comics</Publisher>
  <Summary>A comic that describes itself.</Summary>
  <Year>1997</Year><Month>4</Month><Day>9</Day>
  <LanguageISO>en</LanguageISO>
  <AgeRating>Teen</AgeRating>
  <Manga>YesAndRightToLeft</Manga>
  <Writer>Jeph Loeb</Writer>
  <Penciller>Tim Sale</Penciller>
  <Pages>
    <Page Image="0" Type="FrontCover" ImageWidth="600" ImageHeight="900" />
    <Page Image="1" Type="Story" />
    <Page Image="2" DoublePage="true" ImageWidth="1200" ImageHeight="900" />
  </Pages>
</ComicInfo>
XML;

$tagged = $dir . '/Navigator Tagged 007 (1997).cbz';
@unlink($tagged);
$zip = new ZipArchive();
if ($zip->open($tagged, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "cannot create tagged cbz\n");
    exit(1);
}
foreach ($pages as $n => $bytes) {
    $zip->addFromString(sprintf('page-%02d.jpg', $n), $bytes);
}
$zip->addFromString('ComicInfo.xml', $comicInfo);
$zip->close();

// ---- PDF (image-based, native read path) ----
function assemble(array $objects): string
{
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "$number 0 obj\n$body\nendobj\n";
    }
    $highest = (int) max(array_keys($objects));
    $xref = strlen($pdf);
    $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $highest + 1);
    for ($number = 1; $number <= $highest; ++$number) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
    }
    return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", $highest + 1, $xref);
}

$jpegs = array_values($pages);
$objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
$next = 3;
$pageNumbers = $imageNumbers = [];
foreach ($jpegs as $i => $_) { $pageNumbers[$i] = $next++; }
foreach ($jpegs as $i => $_) { $imageNumbers[$i] = $next++; }
$contents = $next++;

$kids = [];
foreach ($jpegs as $i => $jpeg) {
    $kids[] = $pageNumbers[$i] . ' 0 R';
    $objects[$pageNumbers[$i]] = sprintf(
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 900] /Resources << /XObject << /Im0 %d 0 R >> >> /Contents %d 0 R >>',
        $imageNumbers[$i],
        $contents
    );
    $size = getimagesizefromstring($jpeg);
    $objects[$imageNumbers[$i]] = sprintf(
        "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
        $size[0],
        $size[1],
        strlen($jpeg),
        $jpeg
    );
}
$objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($jpegs));
$objects[$contents] = "<< /Length 30 >>\nstream\nq 600 0 0 900 0 0 cm /Im0 Do Q\nendstream";

file_put_contents($dir . '/Navigator Test PDF.pdf', assemble($objects));

foreach (glob($dir . '/*') as $f) {
    printf("%s (%d bytes)\n", basename($f), filesize($f));
}
