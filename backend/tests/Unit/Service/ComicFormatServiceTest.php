<?php

namespace App\Tests\Unit\Service;

use App\Entity\ComicFormatConfiguration;
use App\Enum\ComicSourceType;
use App\Service\ComicFormatService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

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

    /**
     * A present 7z binary is not the same as CBR working: several
     * distributions ship the RAR decoder separately, and reporting CBR as
     * available on such a host lets an admin enable a format the server cannot
     * read. The expectation is computed here the same way an operator would
     * check by hand, so this fails if availability goes back to "is 7z there".
     */
    public function testCbrAvailabilityTracksRarSupportRatherThanTheBinaryAlone(): void
    {
        $sevenZip = (new ExecutableFinder())->find('7z');
        if ($sevenZip === null) self::markTestSkipped('7z is not installed.');

        $process = new Process([$sevenZip, 'i']);
        $process->run();
        $readsRar = $process->isSuccessful() && preg_match('/\bRar5?\b/', $process->getOutput()) === 1;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(new ComicFormatConfiguration());
        $status = (new ComicFormatService($entityManager))->status();

        self::assertSame($readsRar, $status['cbr']['available']);
        // The formats 7z always carries stay tied to the binary being there.
        self::assertTrue($status['cb7']['available']);
        self::assertTrue($status['cbt']['available']);
    }

    /**
     * Shared hosting routinely puts proc_open in disable_functions, and the FTP
     * deployment guide supports that hosting. Two things have to hold there:
     * probing must not throw out of isEnabled() and take the upload and
     * configuration endpoints with it, and the two native formats — CBZ and
     * PDF, neither of which needs an external tool — must stay available.
     */
    public function testKeepsTheNativeFormatsWhereSubprocessesAreForbidden(): void
    {
        $php = (new PhpExecutableFinder())->find();
        if ($php === false) self::markTestSkipped('No PHP binary to run the isolated check with.');

        $script = <<<'PHP'
            require getenv('APP_AUTOLOAD');
            $availability = (new App\ComicSource\ComicRuntimeProbe())->availability();
            echo json_encode([
                'canShellOut' => App\ComicSource\ComicRuntimeProbe::canRunExternalTools(),
                'cbz' => $availability['cbz'],
                'pdf' => $availability['pdf'],
                'cbr' => $availability['cbr'],
            ]);
        PHP;

        // A real subprocess, because the point is what PHP does when proc_open
        // is genuinely gone rather than mocked away.
        $process = new Process([$php, '-d', 'disable_functions=proc_open', '-r', $script]);
        $process->setEnv(['APP_AUTOLOAD' => \dirname(__DIR__, 3).'/vendor/autoload.php']);
        $process->run();

        if (!$process->isSuccessful()) {
            self::fail('Probing threw where proc_open is disabled: '.$process->getErrorOutput());
        }

        $result = json_decode($process->getOutput(), true);
        self::assertIsArray($result, 'Unexpected probe output: '.$process->getOutput());
        self::assertFalse($result['canShellOut'], 'The isolated run was supposed to have proc_open disabled.');
        self::assertTrue($result['cbz'], 'CBZ needs nothing external and must survive.');
        self::assertTrue($result['pdf'], 'PDF is read natively and must survive alongside CBZ.');
        self::assertFalse($result['cbr'], 'CBR needs 7z, which cannot be run here.');
    }


    public function testEveryFormatTellsAnAdministratorWhatToInstall(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(new ComicFormatConfiguration());

        foreach ((new ComicFormatService($entityManager))->status() as $format => $detail) {
            self::assertNotSame([], $detail['requirements'], sprintf('%s lists no requirements.', $format));
            self::assertNotSame('', $detail['hint'], sprintf('%s offers no installation hint.', $format));
        }
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
