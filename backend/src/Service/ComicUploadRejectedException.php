<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A vetted upload rejection whose message is safe and useful to the uploader.
 */
final class ComicUploadRejectedException extends \RuntimeException
{
}
