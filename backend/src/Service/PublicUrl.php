<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PublicUrl
{
    private string $baseUrl;

    public function __construct(#[Autowire('%app_url%')] string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function base(): string
    {
        return $this->baseUrl;
    }

    public function to(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }
}
