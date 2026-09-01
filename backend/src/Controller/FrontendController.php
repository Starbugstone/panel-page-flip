<?php

namespace App\Controller;

use App\Service\FrontendRouteRegistry;
use App\Service\ContentSecurityPolicy;
use App\Service\PublicUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to serve the React frontend application
 */
class FrontendController extends AbstractController
{
    public function __construct(
        private readonly FrontendRouteRegistry $routes,
        private readonly PublicUrl $publicUrl,
        private readonly ContentSecurityPolicy $contentSecurityPolicy,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Serves the React application for any non-API routes
     * This allows React Router to handle client-side routing
     */
    #[Route('/{reactRouting}', requirements: ['reactRouting' => '^(?!api|_wdt|_profiler).+'], defaults: ['reactRouting' => ''], name: 'frontend_index')]
    public function index(string $reactRouting = ''): Response
    {
        // Return the index.html file that loads the React app
        $indexFile = $this->projectDir.'/public/index.html';

        if (!is_file($indexFile) || !is_readable($indexFile)) {
            throw $this->createNotFoundException('Frontend application not found');
        }

        $content = @file_get_contents($indexFile);
        if ($content === false) {
            throw $this->createNotFoundException('Frontend application not found');
        }
        
        $path = $reactRouting === '' ? '/' : '/'.ltrim($reactRouting, '/');
        $isKnownRoute = $this->routes->isKnown($path);
        $status = $isKnownRoute ? Response::HTTP_OK : Response::HTTP_NOT_FOUND;
        $headers = ['Content-Type' => 'text/html'];

        // Public informational pages belong in search results. Authenticated,
        // tokenized, and error pages remain usable SPA routes without being indexed.
        if (!$this->routes->isIndexable($path)) {
            $headers['X-Robots-Tag'] = 'noindex, follow';
        }

        // Apache routes documents through Symfony, so give non-JavaScript
        // crawlers the right canonical immediately. Nginx serves the same
        // value from the build and React updates legal-page metadata in place.
        $canonicalUrl = htmlspecialchars(
            $path === '/' ? $this->publicUrl->base().'/' : $this->publicUrl->to($path),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        // Match on identifying attributes so attribute order / self-closing
        // style changes in index.html do not silently leave stale URLs.
        // preg_replace_callback, not preg_replace: the URL carries the requested
        // path, and a path such as /$0 would otherwise be expanded as a
        // backreference and splice the matched tag into its own href.
        $canonicalTag = '<link rel="canonical" href="'.$canonicalUrl.'" />';
        $openGraphTag = '<meta property="og:url" content="'.$canonicalUrl.'" />';
        $canonicalCount = 0;
        $content = preg_replace_callback(
            '#<link\b[^>]*\brel=["\']canonical["\'][^>]*/?>#i',
            static fn (): string => $canonicalTag,
            $content,
            1,
            $canonicalCount
        ) ?? $content;
        $openGraphCount = 0;
        $content = preg_replace_callback(
            '#<meta\b[^>]*\bproperty=["\']og:url["\'][^>]*/?>#i',
            static fn (): string => $openGraphTag,
            $content,
            1,
            $openGraphCount
        ) ?? $content;
        if ($canonicalCount === 0 || $openGraphCount === 0) {
            $missingTags = [];
            if ($canonicalCount === 0) {
                $missingTags[] = $canonicalTag;
            }
            if ($openGraphCount === 0) {
                $missingTags[] = $openGraphTag;
            }
            $content = str_replace('</head>', "    ".implode("\n    ", $missingTags)."\n  </head>", $content);
        }

        $nonce = null;
        if ($this->contentSecurityPolicy->googleScriptsEnabled()) {
            $nonce = $this->contentSecurityPolicy->nonce();
            $content = $this->contentSecurityPolicy->nonceScripts($content, $nonce);
        }
        $headers['Content-Security-Policy'] = $this->contentSecurityPolicy->header($nonce);

        return new Response($content, $status, $headers);
    }
}
