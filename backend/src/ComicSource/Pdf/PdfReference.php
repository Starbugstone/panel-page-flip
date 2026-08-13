<?php

namespace App\ComicSource\Pdf;

/**
 * An indirect reference, `12 0 R`, left unresolved until something asks for it.
 *
 * Resolving eagerly would mean pulling most of a document into memory to read
 * one page of it, which is the opposite of what a lazy page provider is for.
 */
final class PdfReference
{
    public function __construct(public readonly int $number, public readonly int $generation)
    {
    }

    public function key(): string
    {
        return $this->number.'_'.$this->generation;
    }
}
