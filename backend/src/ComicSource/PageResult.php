<?php

namespace App\ComicSource;

final class PageResult
{
    public function __construct(public readonly string $content, public readonly string $mimeType)
    {
    }
}
