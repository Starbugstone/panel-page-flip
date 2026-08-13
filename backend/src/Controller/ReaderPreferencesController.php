<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Reader\ReaderPreferences;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/reader/preferences', name: 'api_reader_preferences_')]
final class ReaderPreferencesController extends AbstractController
{
    #[Route('', name: 'get', methods: ['GET'])]
    public function get(ReaderPreferences $readerPreferences): JsonResponse
    {
        $user = $this->authenticatedUser();

        return $this->json([
            'preferences' => $readerPreferences->normalize($user->getReaderPreferences()),
        ]);
    }

    #[Route('', name: 'replace', methods: ['PUT'])]
    public function replace(
        Request $request,
        ReaderPreferences $readerPreferences,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_keys($payload) !== ['preferences']) {
            return $this->json(
                ['message' => 'The request must contain only preferences.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $preferences = $readerPreferences->validate($payload['preferences']);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->authenticatedUser();
        $user->setReaderPreferences($preferences);
        $entityManager->flush();

        return $this->json(['preferences' => $preferences]);
    }

    #[Route('', name: 'reset', methods: ['DELETE'])]
    public function reset(
        ReaderPreferences $readerPreferences,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $user = $this->authenticatedUser();
        $user->setReaderPreferences(null);
        $entityManager->flush();

        return $this->json(['preferences' => $readerPreferences->defaults()]);
    }

    private function authenticatedUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
