<?php

namespace App\Tests\Unit\Service;

use App\Entity\ComicFormatConfiguration;
use App\Enum\ComicSourceType;
use App\Service\ComicFormatService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ComicFormatServiceTest extends TestCase
{
    public function testDefaultsToCbzOnly(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->with(ComicFormatConfiguration::class, 1)->willReturn(new ComicFormatConfiguration());
        $service = new ComicFormatService($entityManager);
        self::assertSame([ComicSourceType::CBZ], $service->enabled());
        self::assertTrue($service->status()['cbz']['enabled']);
        self::assertFalse($service->status()['pdf']['enabled']);
    }

    public function testCannotEnableAnUnavailableFormat(): void
    {
        $configuration = new ComicFormatConfiguration();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($configuration);
        $service = new ComicFormatService($entityManager);
        $unavailable = array_key_first(array_filter($service->status(), static fn (array $status): bool => !$status['available'] && !$status['enabled']));
        if ($unavailable === null) self::markTestSkipped('Every optional runtime is installed.');
        $this->expectException(\RuntimeException::class);
        $service->save([ComicSourceType::from($unavailable)]);
    }

    public function testSaveAlwaysPersistsCbzWithoutCreatingASecondConfiguration(): void
    {
        $configuration = new ComicFormatConfiguration();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('find')->willReturn($configuration);
        $entityManager->expects(self::once())->method('flush');

        $service = new ComicFormatService($entityManager);
        $service->save([]);

        self::assertSame([ComicSourceType::CBZ], $configuration->getEnabledFormats());
        self::assertSame([ComicSourceType::CBZ], $service->enabled());
    }
}
