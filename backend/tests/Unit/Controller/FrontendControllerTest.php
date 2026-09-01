<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\FrontendController;
use App\Service\ContentSecurityPolicy;
use App\Service\FrontendRouteRegistry;
use App\Service\PublicUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FrontendControllerTest extends TestCase
{
    private ?string $projectDir = null;

    public function testAnUnreadableFrontendEntryPointIsReportedAsMissing(): void
    {
        $this->projectDir = sys_get_temp_dir().'/panel-page-flip-frontend-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/public/index.html', 0775, true);

        $controller = new FrontendController(
            $this->createMock(FrontendRouteRegistry::class),
            $this->createMock(PublicUrl::class),
            $this->createMock(ContentSecurityPolicy::class),
            $this->projectDir,
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->index();
    }

    protected function tearDown(): void
    {
        if ($this->projectDir !== null) {
            @rmdir($this->projectDir.'/public/index.html');
            @rmdir($this->projectDir.'/public');
            @rmdir($this->projectDir);
        }

        parent::tearDown();
    }
}
