<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
// Removed: use Symfony\Component\HttpFoundation\Response;
// Removed: use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    // The login method previously here has been removed as json_login handles /api/login directly.

    #[Route(path: '/api/logout', name: 'api_logout')] // Updated path and name
    public function logout(): void
    {
        // This method can be blank - it will be intercepted by the logout key on your firewall.
        // The logic for logout is handled by Symfony's security system based on the configuration
        // in security.yaml.
        throw new \LogicException('This method should be blank - it will be intercepted by the logout key on your firewall.');
    }
}
