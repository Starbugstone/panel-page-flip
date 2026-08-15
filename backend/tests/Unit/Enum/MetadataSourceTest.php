<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MetadataSource;
use PHPUnit\Framework\TestCase;

final class MetadataSourceTest extends TestCase
{
    public function testTheUsersOwnAnswerOutranksEverythingElse(): void
    {
        self::assertTrue(MetadataSource::User->outranks(MetadataSource::ComicInfo));
        self::assertTrue(MetadataSource::ComicInfo->outranks(MetadataSource::Provider));
        self::assertTrue(MetadataSource::Provider->outranks(MetadataSource::Filename));
        self::assertFalse(MetadataSource::Filename->outranks(MetadataSource::User));
    }
}
