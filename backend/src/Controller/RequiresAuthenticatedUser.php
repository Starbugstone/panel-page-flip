<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\UnauthenticatedException;

/**
 * One way for an API action to get the user it cannot work without.
 *
 * {@see \Symfony\Bundle\FrameworkBundle\Controller\AbstractController::getUser()}
 * returns the framework's UserInterface, which says nothing about `getId()` or
 * about the services here that take a {@see User}. Every action that needed one
 * therefore narrowed it and answered 401 itself, in three different dialects and
 * two different response shapes.
 *
 * Requiring the user is the common case and now reads as one line; the answer
 * when there is none belongs to
 * {@see \App\EventSubscriber\ApiExceptionSubscriber}. Use {@see currentUser()}
 * only where absence is genuinely a branch rather than a refusal.
 *
 * @method \Symfony\Component\Security\Core\User\UserInterface|null getUser()
 */
trait RequiresAuthenticatedUser
{
    /**
     * @throws UnauthenticatedException when nobody is signed in
     */
    protected function requireUser(): User
    {
        return $this->currentUser() ?? throw new UnauthenticatedException();
    }

    /**
     * The signed-in user, as the entity this application actually stores, or
     * null when there is none.
     */
    protected function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
