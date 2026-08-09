<?php

namespace App\Service;

use App\Entity\ComicFormatConfiguration;
use App\Enum\ComicSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\ExecutableFinder;

final class ComicFormatService
{
    private ?ComicFormatConfiguration $configuration = null;

    /** @var array<string, bool>|null */
    private ?array $availability = null;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** @return list<ComicSourceType> */
    public function enabled(): array
    {
        return $this->configuration()->getEnabledFormats();
    }

    public function isEnabled(ComicSourceType $type): bool
    {
        return in_array($type, $this->enabled(), true) && $this->availability()[$type->value];
    }

    /** @return array<string, array{available: bool, enabled: bool, requirements: list<string>}> */
    public function status(bool $refreshAvailability = false): array
    {
        if ($refreshAvailability) $this->availability = null;
        $enabled = array_fill_keys(array_map(static fn (ComicSourceType $type) => $type->value, $this->enabled()), true);
        $availability = $this->availability();
        $requirements = ['cbz' => ['PHP ZIP extension'], 'cbr' => ['7z'], 'cb7' => ['7z'], 'cbt' => ['7z'], 'pdf' => ['pdfinfo', 'pdftocairo']];
        $result = [];
        foreach (ComicSourceType::cases() as $type) $result[$type->value] = ['available' => $availability[$type->value], 'enabled' => isset($enabled[$type->value]), 'requirements' => $requirements[$type->value]];
        return $result;
    }

    /** @param list<ComicSourceType> $formats */
    public function save(array $formats): void
    {
        $configuration = $this->configuration();
        $normalised = [ComicSourceType::CBZ->value => ComicSourceType::CBZ];
        foreach ($formats as $format) $normalised[$format->value] = $format;
        $formats = array_values($normalised);
        $availability = $this->availability();
        foreach ($formats as $format) {
            if (!$availability[$format->value]) throw new \RuntimeException(sprintf('%s cannot be enabled because its runtime dependencies are unavailable.', strtoupper($format->value)));
        }
        $configuration->setEnabledFormats($formats);
        $this->entityManager->flush();
    }

    /** @return array<string, bool> */
    private function availability(): array
    {
        if ($this->availability !== null) return $this->availability;

        $finder = new ExecutableFinder();
        $hasSevenZip = $finder->find('7z') !== null;
        $hasPdf = $finder->find('pdfinfo') !== null && $finder->find('pdftocairo') !== null;

        return $this->availability = [
            'cbz' => class_exists(\ZipArchive::class),
            'cbr' => $hasSevenZip,
            'cb7' => $hasSevenZip,
            'cbt' => $hasSevenZip,
            'pdf' => $hasPdf,
        ];
    }

    private function configuration(): ComicFormatConfiguration
    {
        if ($this->configuration !== null) return $this->configuration;

        $configuration = $this->entityManager->find(ComicFormatConfiguration::class, 1);
        if ($configuration === null) {
            $configuration = new ComicFormatConfiguration();
            $this->entityManager->persist($configuration);
        }
        return $this->configuration = $configuration;
    }
}
