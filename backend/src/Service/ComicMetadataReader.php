<?php

declare(strict_types=1);

namespace App\Service;

use App\ComicSource\ComicInfoSourceFactory;
use App\Enum\ComicSourceType;
use App\Metadata\ComicInfo;
use App\Metadata\ComicInfoParser;
use Psr\Log\LoggerInterface;

/**
 * A comic's embedded metadata, or null when it has none this can use.
 *
 * Never throws: metadata is an enrichment, and a source that cannot supply it
 * is still a comic worth importing.
 */
final class ComicMetadataReader
{
    public function __construct(
        private readonly ComicInfoSourceFactory $sources,
        private readonly ComicInfoParser $parser,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function read(string $sourcePath, ComicSourceType $type): ?ComicInfo
    {
        $source = $this->sources->for($type);
        if ($source === null) {
            return null;
        }

        try {
            $xml = $source->readComicInfoXml($sourcePath, $type);

            return $xml === null ? null : $this->parser->parse($xml);
        } catch (\Throwable $exception) {
            $this->logger?->debug('Embedded comic metadata could not be read.', ['reason' => $exception->getMessage()]);

            return null;
        }
    }
}
