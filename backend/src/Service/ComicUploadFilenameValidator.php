<?php

namespace App\Service;

use App\Enum\ComicSourceType;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ComicUploadFilenameValidator
{
    private const MAX_ORIGINAL_FILENAME_BYTES = 255;

    public function validate(string $filename): string
    {
        $hasValidLength = $filename !== '' && strlen($filename) <= self::MAX_ORIGINAL_FILENAME_BYTES;
        $isValidUtf8 = preg_match('//u', $filename) === 1;
        $containsPathSeparator = str_contains($filename, '/') || str_contains($filename, '\\');
        $containsControlCharacter = preg_match('/[\x00-\x1F\x7F]/u', $filename) === 1;
        $hasSupportedExtension = in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ComicSourceType::extensions(), true);
        $hasUsableStem = preg_match('/[^\s.]/u', pathinfo($filename, PATHINFO_FILENAME)) === 1;

        if (!$hasValidLength
            || !$isValidUtf8
            || $containsPathSeparator
            || $containsControlCharacter
            || !$hasSupportedExtension
            || !$hasUsableStem
        ) {
            throw new BadRequestHttpException('Invalid filename.');
        }

        return $filename;
    }
}
