<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\OAuth\OAuthSessionState;
use App\Service\AccountDeletionService;
use App\Service\PersonalDataExporter;
use App\Service\SecurityAuditLogger;
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
        SecurityAuditLogger $securityLogger,
        OAuthSessionState $oauthSession,
    ): JsonResponse {
        $user = $this->authenticatedUser();
        $data = \App\Http\JsonRequestDecoder::decode($request);
        if (!is_array($data)) {
            return $this->json(['message' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        if (($data['confirmation'] ?? null) !== 'DELETE') {
            return $this->json(
                ['message' => 'Enter DELETE to confirm permanent account deletion.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // Recorded before the password check, so an erasure request is on the
        // record whether or not the person asking got their own password right.
        $securityLogger->audit(SecurityAuditLogger::USER_ACCOUNT_DELETION_REQUESTED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
        ]);

        if ($user->hasPassword()) {
            $password = (string) ($data['currentPassword'] ?? '');
            if ($password === '' || !$passwordHasher->isPasswordValid($user, $password)) {
                return $this->json(['message' => 'The current password is incorrect.'], Response::HTTP_FORBIDDEN);
            }
        } elseif (!$oauthSession->consumeRecentReauthentication($request->getSession(), (int) $user->getId())) {
            return $this->json([
                'message' => 'Reauthenticate with a connected provider before deleting this account.',
                'requiresProviderReauthentication' => true,
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $accountDeletion->delete($user, $user);
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
