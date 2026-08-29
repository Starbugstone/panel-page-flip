<?php

declare(strict_types=1);

namespace App\Tests\Unit\Configuration;

use App\Service\AdvertisingConfiguration;
use App\Service\AppDataEncryptionService;
use App\Service\ShareContentCodeLifetime;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `backend/.env.example` is the operator-facing catalogue of every variable
 * the application reads. A setting that exists only in YAML or an Autowire
 * attribute is a setting nobody can find when they come to fill in a
 * deployment.
 */
final class EnvExampleCompletenessTest extends TestCase
{
    /**
     * Test-only variables: ParaTest's schema suffix, and helpers used by a
     * couple of unit tests that spawn a subprocess. They are not operator
     * configuration.
     *
     * @var list<string>
     */
    private const EXCLUDED = [
        'TEST_TOKEN',
        'APP_AUTOLOAD',
        'APP_PDF',
    ];

    public function testEveryApplicationEnvVarIsDocumentedWithAnExample(): void
    {
        $documented = self::documentedAssignments();
        $missing = [];
        $withoutExample = [];

        foreach (self::discoveredEnvVars() as $name) {
            if (!isset($documented[$name])) {
                $missing[] = $name;
                continue;
            }

            $hasValue = false;
            foreach ($documented[$name] as $value) {
                if ($value !== '') {
                    $hasValue = true;
                    break;
                }
            }
            if (!$hasValue) {
                $withoutExample[] = $name;
            }
        }

        self::assertSame([], $missing, 'Variables the application reads that .env.example does not list.');
        self::assertSame([], $withoutExample, 'Variables listed in .env.example without an example value.');
    }

    public function testAppDataKeyExampleIsAKeyTheApplicationWouldAccept(): void
    {
        $values = self::documentedAssignments()['APP_DATA_KEY'] ?? [];
        self::assertNotEmpty($values, 'APP_DATA_KEY must appear in .env.example.');

        $accepted = 0;
        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }
            try {
                new AppDataEncryptionService($value);
                ++$accepted;
            } catch (\InvalidArgumentException) {
                self::fail(sprintf('APP_DATA_KEY example %s is not 32 random bytes as hex or Base64.', $value));
            }
        }

        self::assertGreaterThan(0, $accepted, 'APP_DATA_KEY needs at least one valid example value.');
    }

    public function testAdsenseClientExampleIsEmptyOrAPublisherId(): void
    {
        $values = self::documentedAssignments()['ADSENSE_CLIENT'] ?? [];
        self::assertNotEmpty($values, 'ADSENSE_CLIENT must appear in .env.example.');

        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }

            $configuration = new AdvertisingConfiguration(true, $value, new NullLogger());
            self::assertTrue(
                $configuration->isEnabled(),
                sprintf('ADSENSE_CLIENT example %s is not a publisher id the application would accept.', $value)
            );
        }
    }

    public function testShareContentCodeTtlExampleIsInsideTheAcceptedRange(): void
    {
        $values = self::documentedAssignments()['SHARE_CONTENT_CODE_TTL_DAYS'] ?? [];
        self::assertNotEmpty($values, 'SHARE_CONTENT_CODE_TTL_DAYS must appear in .env.example.');

        foreach ($values as $value) {
            if ($value === '' || !ctype_digit($value)) {
                continue;
            }

            $days = (int) $value;
            self::assertGreaterThanOrEqual(1, $days);
            self::assertLessThanOrEqual(ShareContentCodeLifetime::MAX_DAYS, $days);
            new ShareContentCodeLifetime($days);
        }
    }

    /**
     * @return list<string>
     */
    private static function discoveredEnvVars(): array
    {
        $names = [
            'APP_ENV' => true,
            'APP_DEBUG' => true,
        ];

        $roots = [
            dirname(__DIR__, 3).'/config',
            dirname(__DIR__, 3).'/src',
            dirname(__DIR__, 3).'/bin',
            dirname(__DIR__, 3).'/public',
        ];
        $postDeploy = dirname(__DIR__, 4).'/scripts/deploy/_post-deploy.php.dist';
        $files = self::phpAndYamlFiles($roots);
        if (is_file($postDeploy)) {
            $files[] = $postDeploy;
        }

        foreach ($files as $path) {
            $contents = (string) file_get_contents($path);
            foreach (self::envNamesIn($contents) as $name) {
                $names[$name] = true;
            }
        }

        foreach (self::EXCLUDED as $name) {
            unset($names[$name]);
        }

        $list = array_keys($names);
        sort($list);

        return $list;
    }

    /**
     * @param list<string> $roots
     *
     * @return list<string>
     */
    private static function phpAndYamlFiles(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                if (preg_match('/\.(php|ya?ml)$/', $file->getFilename()) !== 1) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function envNamesIn(string $contents): array
    {
        $names = [];

        if (preg_match_all('/%env\(([^%)]+)\)%/', $contents, $matches) > 0) {
            foreach ($matches[1] as $expression) {
                $parts = explode(':', $expression);
                $candidate = (string) end($parts);
                if (preg_match('/^[A-Z][A-Z0-9_]*$/', $candidate) === 1) {
                    $names[$candidate] = true;
                }
            }
        }

        foreach ([
            "/getenv\(\s*'([A-Z][A-Z0-9_]*)'\s*\)/",
            '/getenv\(\s*"([A-Z][A-Z0-9_]*)"\s*\)/',
            "/\$_ENV\[\s*'([A-Z][A-Z0-9_]*)'\s*\]/",
            '/\$_ENV\[\s*"([A-Z][A-Z0-9_]*)"\s*\]/',
        ] as $pattern) {
            if (preg_match_all($pattern, $contents, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function documentedAssignments(): array
    {
        $path = dirname(__DIR__, 3).'/.env.example';
        self::assertFileExists($path);

        $assignments = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
                continue;
            }
            $assignments[$matches[1]][] = self::unquote(trim($matches[2]));
        }

        return $assignments;
    }

    private static function unquote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
