<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AdvertisingConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/ads.txt`, built from the publisher id rather than kept as a file.
 *
 * The IAB record is the same three constants for every AdSense publisher plus
 * their own id, so a committed file would be a copy of `ADSENSE_CLIENT` that
 * nothing keeps in step — and a stale ads.txt names somebody else as entitled to
 * sell this domain's inventory.
 *
 * Absent rather than empty when advertising is off: a self-hosted installation
 * with no AdSense account has no authorised seller to declare, and publishing an
 * empty file says something different from publishing nothing.
 *
 * The priority beats {@see FrontendController}, which otherwise answers every
 * path that is not `/api`.
 */
final class AdsTxtController extends AbstractController
{
    /** Google's fixed certification authority id for AdSense inventory. */
    private const GOOGLE_CERTIFICATION_AUTHORITY_ID = 'f08c47fec0942fa0';

    public function __construct(private readonly AdvertisingConfiguration $advertising)
    {
    }

    #[Route('/ads.txt', name: 'ads_txt', methods: ['GET'], priority: 10)]
    public function __invoke(): Response
    {
        $client = $this->advertising->client();
        if ($client === null) {
            throw $this->createNotFoundException('This installation does not serve advertising.');
        }

        // The publisher id without its `ca-` prefix is what the record names.
        $publisherId = substr($client, strlen('ca-'));

        return new Response(
            sprintf("google.com, %s, DIRECT, %s\n", $publisherId, self::GOOGLE_CERTIFICATION_AUTHORITY_ID),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
}
