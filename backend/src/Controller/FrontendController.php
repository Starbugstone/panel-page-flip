<?php

namespace App\Controller;

use App\Service\FrontendRouteRegistry;
use App\Service\PublicUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        $indexFile = $this->getParameter('kernel.project_dir') . '/public/index.html';
        
        if (!file_exists($indexFile)) {
            throw $this->createNotFoundException('Frontend application not found');
        }
        
        $content = file_get_contents($indexFile);
        
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
        $canonicalCount = 0;
        $content = preg_replace(
            '#<link rel="canonical" href="[^"]*"\s*/>#',
            '<link rel="canonical" href="'.$canonicalUrl.'" />',
            $content,
            1,
            $canonicalCount
        ) ?? $content;
        $openGraphCount = 0;
        $content = preg_replace(
            '#<meta property="og:url" content="[^"]*"\s*/>#',
            '<meta property="og:url" content="'.$canonicalUrl.'" />',
            $content,
            1,
            $openGraphCount
        ) ?? $content;
        if ($canonicalCount === 0 || $openGraphCount === 0) {
            $missingTags = [];
            if ($canonicalCount === 0) {
                $missingTags[] = '<link rel="canonical" href="'.$canonicalUrl.'" />';
            }
            if ($openGraphCount === 0) {
                $missingTags[] = '<meta property="og:url" content="'.$canonicalUrl.'" />';
            }
            $content = str_replace('</head>', "    ".implode("\n    ", $missingTags)."\n  </head>", $content);
        }

        return new Response($content, $status, $headers);
    }
}
