<?php

namespace App\Controller;

use App\Repository\ComicRepository;
use App\Service\StorageQuotaService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

class MeController extends AbstractController
{
    use RequiresAuthenticatedUser;

    #[Route('/api/me', name: 'api_me', methods: ['GET', 'POST'])]
    public function me(Request $request, SessionInterface $session, LoggerInterface $logger): JsonResponse
    {
        // Public pages use GET to discover whether a session exists. Being
        // signed out is expected there, so represent it as data rather than a
        // failed request that browsers report as a console error. POST is the
        // authenticated keep-alive route and is still protected by security.
        $user = $request->isMethod('POST') ? $this->requireUser() : $this->currentUser();
        if (null === $user) {
            return $this->json([
                'user' => null,
                'sessionRefreshed' => false,
            ]);
        }

        $sessionRefreshed = false;
        if ($request->isMethod('POST')) {
            try {
                if (!$session->isStarted()) {
                    $session->start();
                }

                $session->set('last_activity', time());
                $sessionRefreshed = true;
            } catch (\Throwable $e) {
                $logger->warning('Session refresh failed.', ['exception' => $e]);
            }
        }

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                // The account's own public identity, so the UI can show it back
                // without a second request. Everywhere a *registered other
                // person* is named, this is the field the client prefers over
                // the address.
                'username' => $user->getUsername(),
                'roles' => $user->getRoles(),
                'isAdmin' => $user->isAdmin(),
            ],
            'sessionRefreshed' => $sessionRefreshed,
        ]);
    }

    /**
     * How much of this account's storage quota is gone.
     *
     * Its own request rather than a field on `/api/me`, which the session
     * monitor polls: a grouped sum over every comic an account owns is cheap
     * once and pointless every thirty seconds.
     *
     * The same three numbers, from the same grouped query, that the admin user
     * list is built from — so an account and the administrator looking at it
     * can never be told different things about the same disk.
     */
    #[Route('/api/me/storage', name: 'api_me_storage', methods: ['GET'])]
    public function storage(
        ComicRepository $comics,
        StorageQuotaService $quota,
    ): JsonResponse {
        $user = $this->requireUser();
        $stats = $comics->getStorageStatsByOwner([(int) $user->getId()])[(int) $user->getId()];

        return $this->json([
            // Raw integers, never a percentage or a formatted string: the
            // client divides, so an account over its quota reads as over rather
            // than as exactly full.
            'comicCount' => $stats['comicCount'],
            'storageUsedBytes' => $stats['storageUsedBytes'],
            'storageQuotaBytes' => $quota->getQuotaBytes($user),
            'unmeasuredComicCount' => $stats['unmeasuredComicCount'],
        ]);
    }
}
