<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AccountDeletionService;
use App\Service\PersonalDataExporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/privacy', name: 'api_privacy_')]
final class PrivacyController extends AbstractController
{
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(PersonalDataExporter $exporter): JsonResponse
    {
        $user = $this->authenticatedUser();
        $response = new JsonResponse($exporter->export($user));
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_PRETTY_PRINT);
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="panel-page-flip-data-%s.json"', (new \DateTimeImmutable())->format('Y-m-d')),
        );

        return $response;
    }

    #[Route('/account', name: 'delete_account', methods: ['DELETE'])]
    public function deleteAccount(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        AccountDeletionService $accountDeletion,
    ): JsonResponse {
        $user = $this->authenticatedUser();
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        if (($data['confirmation'] ?? null) !== 'DELETE') {
            return $this->json(
                ['message' => 'Enter DELETE to confirm permanent account deletion.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $password = (string) ($data['currentPassword'] ?? '');
        if ($password === '' || !$passwordHasher->isPasswordValid($user, $password)) {
            return $this->json(['message' => 'The current password is incorrect.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $accountDeletion->delete($user);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        $request->getSession()->invalidate();

        return $this->json(['message' => 'Your account and personal data were deleted.']);
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
