<?php

namespace App\Service;

use App\ComicSource\ComicRuntimeProbe;
use App\Entity\ComicFormatConfiguration;
use App\Enum\ComicSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ComicFormatService
{
    private const AVAILABILITY_CACHE_KEY = 'comic_formats.availability';
    private const AVAILABILITY_TTL_SECONDS = 300;

    /**
     * What an administrator has to install to turn each format on, in the words
     * of the thing they would type into a package manager. The runtime check
     * below answers "can this server do it"; these answer "what do I do about
     * it", which is the question somebody staring at a red row actually has.
     */
    private const REQUIREMENTS = [
        'cbz' => ['PHP ZIP extension'],
        'cbr' => ['7z with RAR support'],
        'cb7' => ['7z'],
        'cbt' => ['7z'],
        'pdf' => ['pdfinfo', 'pdftocairo'],
    ];

    private const INSTALL_HINTS = [
        'cbz' => 'Rebuild the PHP image with the zip extension enabled (docker-php-ext-install zip).',
        'cbr' => 'Install p7zip-full (Debian/Ubuntu) or 7zip. Some distributions ship the RAR decoder separately as p7zip-rar; without it 7z still reads 7z and tar but never CBR.',
        'cb7' => 'Install p7zip-full (Debian/Ubuntu), p7zip-plugins (RHEL/Fedora), or 7zip.',
        'cbt' => 'Install p7zip-full (Debian/Ubuntu), p7zip-plugins (RHEL/Fedora), or 7zip.',
        'pdf' => 'Install poppler-utils, which provides both pdfinfo and pdftocairo.',
    ];

    /**
     * Told to an administrator whose host will not let PHP start a subprocess
     * at all, which is the normal state of shared hosting. "Install
     * poppler-utils" is useless advice to somebody with no shell, so say what
     * is actually true instead.
     */
    private const NO_SUBPROCESS_HINT = 'This server does not allow PHP to run external programs (proc_open is disabled), so no format needing an external tool can work here. Ask your host to enable proc_open, or move to a container or VPS deployment. CBZ needs nothing external and keeps working.';

    private ?ComicFormatConfiguration $configuration = null;

    /** @var array<string, bool>|null */
    private ?array $availability = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicRuntimeProbe $probe = new ComicRuntimeProbe(),
        private readonly ?CacheInterface $availabilityCache = null,
    ) {
    }

    /** @return list<ComicSourceType> */
    public function enabled(): array
    {
        return $this->configuration()->getEnabledFormats();
    }

    public function isEnabled(ComicSourceType $type): bool
    {
        return in_array($type, $this->enabled(), true) && ($this->availability()[$type->value] ?? false);
    }

    /**
     * What this server can do, and what it would need for the rest. Reads no
     * database on purpose: the format check is something an administrator runs
     * while a server is unhealthy, and a diagnostic that needs a working
     * installation to tell you the installation is broken is no diagnostic.
     *
     * @return array<string, array{available: bool, requirements: list<string>, hint: string}>
     */
    public function runtimeReport(bool $refreshAvailability = false): array
    {
        $availability = $this->availability($refreshAvailability);

        $canShellOut = ComicRuntimeProbe::canRunExternalTools();

        $result = [];
        foreach (ComicSourceType::cases() as $type) {
            $needsExternalTool = $type !== ComicSourceType::CBZ;

            $result[$type->value] = [
                'available' => $availability[$type->value] ?? false,
                'requirements' => self::REQUIREMENTS[$type->value] ?? [],
                'hint' => $needsExternalTool && !$canShellOut
                    ? self::NO_SUBPROCESS_HINT
                    : (self::INSTALL_HINTS[$type->value] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{available: bool, enabled: bool, requirements: list<string>, hint: string}>
     */
    public function status(bool $refreshAvailability = false): array
    {
        $enabled = array_fill_keys(array_map(static fn (ComicSourceType $type) => $type->value, $this->enabled()), true);

        $result = [];
        foreach ($this->runtimeReport($refreshAvailability) as $format => $detail) {
            $result[$format] = $detail + ['enabled' => isset($enabled[$format])];
        }

        return $result;
    }

    /** @param list<ComicSourceType> $formats */
    public function save(array $formats): void
    {
        $configuration = $this->configuration();
        $normalised = [ComicSourceType::CBZ->value => ComicSourceType::CBZ];
        foreach ($formats as $format) $normalised[$format->value] = $format;
        $formats = array_values($normalised);

        // Saving is when an administrator has just acted on what the panel told
        // them, so re-probe rather than trusting a cached snapshot that may
        // predate the package they installed a minute ago.
        $availability = $this->availability(true);
        foreach ($formats as $format) {
            if (!($availability[$format->value] ?? false)) {
                throw new \RuntimeException(sprintf(
                    '%s cannot be enabled on this server. Required: %s. %s',
                    strtoupper($format->value),
                    implode(' + ', self::REQUIREMENTS[$format->value] ?? []),
                    self::INSTALL_HINTS[$format->value] ?? ''
                ));
            }
        }

        $configuration->setEnabledFormats($formats);
        $this->entityManager->flush();
    }

    /**
     * @return array<string, bool>
     */
    private function availability(bool $refresh = false): array
    {
        if (!$refresh && $this->availability !== null) return $this->availability;
        if ($this->availabilityCache === null) return $this->availability = $this->probe->availability();

        // Probing shells out, and this is consulted on every upload and every
        // frontend configuration fetch, so the result is cached briefly. INF
        // beta forces an immediate recompute, which is what the administrator
        // asked for when they pressed Verify or saved.
        return $this->availability = $this->availabilityCache->get(
            self::AVAILABILITY_CACHE_KEY,
            function (ItemInterface $item): array {
                $item->expiresAfter(self::AVAILABILITY_TTL_SECONDS);
                return $this->probe->availability();
            },
            $refresh ? INF : null
        );
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
