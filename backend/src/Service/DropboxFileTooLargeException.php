<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A Dropbox file over the upload maximum. Separate from other download failures
 * because it is the one a retry by another route cannot fix.
 *
 * The message names only the file's own name and the limit, so it may be shown
 * to the account the file belongs to.
 */
final class DropboxFileTooLargeException extends \RuntimeException
{
}
