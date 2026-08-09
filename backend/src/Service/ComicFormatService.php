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
    /**
     * The formats that must work everywhere. Both are read with no external
     * tooling, so either one failing means something is wrong with the
     * installation rather than merely unconfigured.
     */
    private const ESSENTIAL = [ComicSourceType::CBZ, ComicSourceType::PDF];

    private const REQUIREMENTS = [
        'cbz' => ['PHP ZIP extension'],
        'cbr' => ['7z with RAR support'],
        'cb7' => ['7z'],
        'cbt' => ['7z'],
        'pdf' => ['nothing, for image-based PDFs'],
    ];

    /**
     * Said about a format that works but does less than it could. PDF is the
     * only one with two tiers: comics arrive as one full-page image per page
     * and are read with no external tool at all, and Poppler is what extends
     * that to documents whose pages have to be drawn.
     */
    private const POPPLER_UPGRADE_NOTE = 'Reading image-based PDFs — which is what scanned and exported comics are — needs nothing installed. Install poppler-utils to also accept PDFs whose pages are drawn rather than scanned; without it those are refused at upload rather than failing later.';

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
     * @return array<string, array{available: bool, essential: bool, requirements: list<string>, hint: string, note: string}>
     */
    public function runtimeReport(bool $refreshAvailability = false): array
    {
        $availability = $this->availability($refreshAvailability);

        $canShellOut = ComicRuntimeProbe::canRunExternalTools();
        $hasPoppler = $this->probe->hasPoppler();

        $result = [];
        foreach (ComicSourceType::cases() as $type) {
            // PDF is excluded: its native reader is pure PHP, so a host that
            // forbids subprocesses still serves it.
            $needsExternalTool = $type !== ComicSourceType::CBZ && $type !== ComicSourceType::PDF;

            $result[$type->value] = [
                'available' => $availability[$type->value] ?? false,
                // CBZ and PDF are what the application promises on any host it
                // runs on, since neither needs anything installed. One of them
                // being unavailable is a broken installation, not a choice an
                // administrator made; the archive formats are optional extras.
                'essential' => in_array($type, self::ESSENTIAL, true),
                'requirements' => self::REQUIREMENTS[$type->value] ?? [],
                'hint' => $needsExternalTool && !$canShellOut
                    ? self::NO_SUBPROCESS_HINT
                    : (self::INSTALL_HINTS[$type->value] ?? ''),
                'note' => $type === ComicSourceType::PDF && !$hasPoppler ? self::POPPLER_UPGRADE_NOTE : '',
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{available: bool, essential: bool, enabled: bool, requirements: list<string>, hint: string, note: string}>
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
