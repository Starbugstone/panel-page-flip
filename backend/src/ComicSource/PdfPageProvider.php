<?php

namespace App\ComicSource;

use App\Enum\ComicSourceType;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

final class PdfPageProvider implements ComicPageProviderInterface
{
    /** @var array<string, ComicSourceInfo> */
    private array $inspections = [];

    public function __construct(private readonly LockFactory $lockFactory)
    {
    }

    public function supports(ComicSourceType $type): bool { return $type === ComicSourceType::PDF; }
    public function inspect(string $sourcePath, ComicSourceType $type): ComicSourceInfo
    {
        $key = $sourcePath.'|'.(@filemtime($sourcePath) ?: 0).'|'.(@filesize($sourcePath) ?: 0);
        if (isset($this->inspections[$key])) return $this->inspections[$key];
        if (file_get_contents($sourcePath, false, null, 0, 5) !== '%PDF-') throw new \RuntimeException('Invalid PDF signature.');
        $process = new Process(['pdfinfo', $sourcePath]); $process->setTimeout(15); $process->run();
        if (!$process->isSuccessful()) throw new \RuntimeException(str_contains(strtolower($process->getErrorOutput()), 'password') ? 'Encrypted PDFs are not supported.' : 'PDF inspection failed.');
        if (preg_match('/^Encrypted:\s+yes/im', $process->getOutput())) throw new \RuntimeException('Encrypted PDFs are not supported.');
        if (!preg_match('/^Pages:\s+(\d+)/mi', $process->getOutput(), $match)) throw new \RuntimeException('PDF page count is unavailable.');
        return $this->inspections[$key] = new ComicSourceInfo((int) $match[1]);
    }
    public function readPage(string $sourcePath, ComicSourceType $type, int $page): PageResult
    {
        $info = $this->inspect($sourcePath, $type); if ($page < 1 || $page > $info->pageCount) throw new \OutOfRangeException('Page not found.');
        $renderLock = $this->lockFactory->createLock('comic-pdf-render', 35.0);
        if (!$renderLock->acquire()) throw new \RuntimeException('The PDF renderer is busy. Please retry shortly.');
        $directory = sys_get_temp_dir().'/comic-pdf-'.bin2hex(random_bytes(12));
        try {
            if (!mkdir($directory, 0700)) throw new \RuntimeException('Cannot create render directory.');
            $prefix = $directory.'/page';
            $process = new Process(['pdftocairo', '-f', (string) $page, '-l', (string) $page, '-singlefile', '-scale-to', '2400', '-jpeg', '-jpegopt', 'quality=88', $sourcePath, $prefix]);
            $process->setTimeout(30); $process->run(); $output = $prefix.'.jpg';
            if (!$process->isSuccessful() || !is_file($output)) throw new \RuntimeException('PDF page rendering failed.');
            $content = file_get_contents($output); if ($content === false) throw new \RuntimeException('Rendered page could not be read.');
            return PageResult::fromImageContent($content);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
            $renderLock->release();
        }
    }
}
