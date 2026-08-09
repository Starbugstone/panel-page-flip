<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use Symfony\Component\Process\Process;

final class PdfPageProvider implements ComicPageProviderInterface
{
    public function supports(ComicSourceType $type): bool { return $type === ComicSourceType::PDF; }
    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo
    {
        if (file_get_contents($sourcePath, false, null, 0, 5) !== '%PDF-') throw new \RuntimeException('Invalid PDF signature.');
        $process = new Process(['pdfinfo', $sourcePath]); $process->setTimeout(15); $process->run();
        if (!$process->isSuccessful()) throw new \RuntimeException(str_contains(strtolower($process->getErrorOutput()), 'password') ? 'Encrypted PDFs are not supported.' : 'PDF inspection failed.');
        if (preg_match('/^Encrypted:\s+yes/im', $process->getOutput())) throw new \RuntimeException('Encrypted PDFs are not supported.');
        if (!preg_match('/^Pages:\s+(\d+)/mi', $process->getOutput(), $match)) throw new \RuntimeException('PDF page count is unavailable.');
        return new ComicSourceInfo((int) $match[1]);
    }
    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult
    {
        $info = $this->inspect($sourcePath, $type); if ($page < 1 || $page > $info->pageCount) throw new \OutOfRangeException('Page not found.');
        $directory = sys_get_temp_dir().'/comic-pdf-'.bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create render directory.');
        $prefix = $directory.'/page';
        try {
            $process = new Process(['pdftocairo', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '2400', '-jpeg', '-jpegopt', 'quality=88', $sourcePath, $prefix]);
            $process->setTimeout(30); $process->run(); $output = $prefix.'.jpg';
            if (!$process->isSuccessful() || !is_file($output)) throw new \RuntimeException('PDF page rendering failed.');
            $content = file_get_contents($output); if ($content === false) throw new \RuntimeException('Rendered page could not be read.');
            return new PageResult($content, 'image/jpeg');
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) @unlink($file); @rmdir($directory);
        }
    }
}
