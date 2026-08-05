<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller to serve the React frontend application
 */
class FrontendController extends AbstractController
{
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
        
        $isKnownRoute = $this->isKnownFrontendRoute($reactRouting);
        $status = $isKnownRoute ? Response::HTTP_OK : Response::HTTP_NOT_FOUND;
        $headers = ['Content-Type' => 'text/html'];

        // Public informational pages belong in search results. Authenticated,
        // tokenized, and error pages remain usable SPA routes without being indexed.
        if (!in_array($reactRouting, ['', 'privacy', 'terms', 'cookies'], true)) {
            $headers['X-Robots-Tag'] = 'noindex, follow';
        }

        return new Response($content, $status, $headers);
    }

    private function isKnownFrontendRoute(string $route): bool
    {
        if (in_array($route, [
            '',
            'login',
            'forgot-password',
            'email-verification',
            'privacy',
            'terms',
            'cookies',
            'dashboard',
            'upload',
            'upload/bulk',
            'admin',
            'dropbox-sync',
            'settings',
        ], true)) {
            return true;
        }

        return preg_match('#^(?:reset-password/[^/]+|read/[^/]+|share/accept/[^/]+)$#', $route) === 1;
    }
}
