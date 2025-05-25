<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for handling admin context detection consistently across the application
 */
class AdminContextService
{
    private RequestStack $requestStack;
    
    /**
     * Constructor
     * 
     * @param RequestStack $requestStack The request stack for accessing the current request
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }
    
    /**
     * Check if the current request is in admin context
     * 
     * @param User|null $user The current user (optional)
     * @return bool True if in admin context, false otherwise
     */
    public function isAdminContext(?User $user = null): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return false;
        }
        
        // Check if adminContext parameter is set to 'true'
        $adminContextParam = $request->query->get('adminContext') === 'true';
        
        // If user is provided, also check if they have admin role
        if ($user !== null) {
            return $adminContextParam && in_array('ROLE_ADMIN', $user->getRoles());
        }
        
        // If no user is provided, just return the parameter value
        return $adminContextParam;
    }
    
    /**
     * Check if a user has access to a resource based on ownership and admin context
     * 
     * @param User $user The current user
     * @param object $resource The resource to check access for (must have a getOwner method)
     * @return bool True if the user has access, false otherwise
     */
    public function hasAccess(User $user, object $resource): bool
    {
        // Method to check if the resource has a getOwner method
        if (!method_exists($resource, 'getOwner')) {
            throw new \InvalidArgumentException('Resource must have a getOwner method');
        }
        
        // Admin in admin context can access any resource
        if ($this->isAdminContext($user)) {
            return true;
        }
        
        // Otherwise, user must be the owner
        $owner = $resource->getOwner();
        return $owner && $owner->getId() === $user->getId();
    }
}
