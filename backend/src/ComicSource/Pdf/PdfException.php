<?php

namespace App\ComicSource\Pdf;

/**
 * Raised when a document cannot be read natively. Never fatal on its own: the
 * page provider treats it as "ask Poppler instead".
 */
final class PdfException extends \RuntimeException
{
}
