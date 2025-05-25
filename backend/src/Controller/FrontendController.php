<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller to serve the React frontend application
 */
class FrontendController extends AbstractController
{
    /**
     * Serves the React application for any non-API routes
     * This allows React Router to handle client-side routing
     * 
     * @Route("/{reactRouting}", requirements={"reactRouting"="^(?!api|_wdt|_profiler).+"}, defaults={"reactRouting"=""}, name="frontend_index")
     */
    public function index(): Response
    {
        // Return the index.html file that loads the React app
        $indexFile = $this->getParameter('kernel.project_dir') . '/public/index.html';
        
        if (!file_exists($indexFile)) {
            throw $this->createNotFoundException('Frontend application not found');
        }
        
        $content = file_get_contents($indexFile);
        
        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/html'
        ]);
    }
}
