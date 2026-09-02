<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DropboxClientFactory;
use App\Service\DropboxConfiguration;
use App\Service\DropboxImportService;
use App\Service\PublicUrl;
use App\Service\SecurityAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/dropbox')]
class DropboxController extends AbstractController
{
    use RequiresAuthenticatedUser;

    private SessionInterface $session;

    public function __construct(
        private readonly DropboxConfiguration $configuration,
        private readonly string $dropboxRedirectUri,
        RequestStack $requestStack,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly PublicUrl $publicUrl,
        private readonly DropboxClientFactory $dropboxClientFactory,
        private readonly DropboxImportService $dropboxImport,
        private readonly int $dropboxSyncLimit
    ) {
        $this->session = $requestStack->getSession();
    }

    #[Route('/connect', name: 'dropbox_connect', methods: ['GET'])]
    public function connect(): Response
    {
        $this->requireUser();
        $unavailable = $this->unavailableResponse();
        if ($unavailable !== null) {
            return $unavailable;
        }

        // Random state, echoed back by Dropbox, to protect the callback from CSRF.
        $state = bin2hex(random_bytes(16));
        $this->session->set('dropbox_oauth2_state', $state);
        $this->logger->debug('Dropbox OAuth state created.', ['session_id' => hash('sha256', $this->session->getId())]);

        $authUrlParams = http_build_query([
            'client_id' => $this->configuration->appKey(),
            'redirect_uri' => $this->dropboxRedirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline', // To get a refresh token
            'state' => $state,
            'scope' => 'files.content.read files.metadata.read',
        ]);

        return new RedirectResponse('https://www.dropbox.com/oauth2/authorize?' . $authUrlParams);
    }

    #[Route('/callback', name: 'dropbox_callback', methods: ['GET'])]
    public function callback(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->requireUser();
        $unavailable = $this->unavailableResponse();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $code = $request->query->get('code');
        $returnedState = $request->query->get('state');
        $savedState = $this->session->get('dropbox_oauth2_state');
        $this->session->remove('dropbox_oauth2_state');

        if (empty($returnedState) || !is_string($savedState) || !hash_equals($savedState, $returnedState)) {
            $this->logger->warning('Dropbox OAuth state mismatch.', ['user_id' => $user->getId()]);
            return $this->json(
                ['error' => 'Dropbox authorization expired or the session ended. Please connect Dropbox again.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (!$code) {
            return $this->json(['error' => 'Dropbox authorization denied or failed. No code received.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.dropboxapi.com/oauth2/token', [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->dropboxRedirectUri,
                    'client_id' => $this->configuration->appKey(),
                    'client_secret' => $this->configuration->appSecret(),
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->warning('Dropbox OAuth token exchange failed.', ['status_code' => $response->getStatusCode()]);
                return $this->json(['error' => 'Failed to obtain Dropbox token.'], Response::HTTP_BAD_GATEWAY);
            }

            $tokenData = $response->toArray();
            $accessToken = $tokenData['access_token'] ?? null;
            if (!$accessToken) {
                $this->logger->error('Dropbox OAuth succeeded but returned no access token.');
                return $this->json(['error' => 'Dropbox connection succeeded but no access token was found in the response.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $user->setDropboxAccessToken($accessToken);
            $user->setDropboxRefreshToken($tokenData['refresh_token'] ?? null);
            $entityManager->flush();

            return new RedirectResponse($this->publicUrl->to('/dropbox-sync').'?status=connected');
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Network error during Dropbox token exchange.', ['exception' => $e]);
            return $this->json(['error' => 'Network error while connecting to Dropbox.'], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Dropbox connection failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['error' => 'Failed to connect Dropbox.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/status', name: 'dropbox_status', methods: ['GET'])]
    public function status(): Response
    {
        $user = $this->requireUser();
        $configured = $this->configuration->isConfigured();
        $connected = $configured && $user->hasDropboxConnection();

        return $this->json([
            'configured' => $configured,
            'connected' => $connected,
            'user' => null,
            'lastSync' => $connected ? $user->getDropboxLastSyncedAt()?->format('c') : null,
        ]);
    }

    private function unavailableResponse(): ?Response
    {
        if ($this->configuration->isConfigured()) {
            return null;
        }

        return $this->json(
            ['error' => DropboxConfiguration::UNAVAILABLE_MESSAGE],
            Response::HTTP_SERVICE_UNAVAILABLE
        );
    }

    #[Route('/disconnect', name: 'dropbox_disconnect', methods: ['POST'])]
    public function disconnect(
        EntityManagerInterface $entityManager,
        SecurityAuditLogger $securityLogger
    ): Response {
        $user = $this->requireUser();

        $user->setDropboxAccessToken(null);
        $user->setDropboxRefreshToken(null);
        $entityManager->flush();

        $securityLogger->audit(SecurityAuditLogger::INTEGRATION_DISCONNECTED, [
            'actor_user_id' => $user->getId(),
            'target_user_id' => $user->getId(),
            'target_type' => 'user',
            'integration' => 'dropbox',
            'disconnected_by_admin' => false,
        ]);

        return $this->json(['message' => 'Dropbox disconnected successfully']);
    }

    #[Route('/files', name: 'dropbox_files', methods: ['GET'])]
    public function files(): Response
    {
        $user = $this->requireUser();

        if (!$user->hasDropboxConnection()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->dropboxClientFactory->createForUser($user);
            $importedIndex = $this->dropboxImport->getImportedIndex($user);

            $files = [];
            foreach ($this->dropboxImport->listComicSourceFiles($client) as $fileInfo) {
                $files[] = [
                    'name' => $fileInfo['name'],
                    'path' => $fileInfo['path'],
                    'size' => $this->dropboxImport->formatFileSize($fileInfo['size']),
                    'modified' => $fileInfo['modified'],
                    'tags' => $fileInfo['tags'],
                    'synced' => $this->dropboxImport->isImported($fileInfo, $importedIndex),
                ];
            }

            return $this->json(['files' => $files]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list Dropbox files.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['error' => 'Failed to fetch Dropbox files.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/import', name: 'dropbox_import_single', methods: ['POST'])]
    public function importSingle(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->requireUser();

        if (!$user->hasDropboxConnection()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        $data = \App\Http\JsonRequestDecoder::decode($request);
        $filePath = $data['path'] ?? null;
        $fileName = $data['fileName'] ?? null;
        $filePath = is_string($filePath) && $filePath !== '' ? $filePath : null;
        $fileName = is_string($fileName) && $fileName !== '' ? $fileName : null;

        if ($filePath === null && $fileName === null) {
            return $this->json(['error' => 'path or fileName is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->dropboxClientFactory->createForUser($user);

            $targetFile = null;
            foreach ($this->dropboxImport->listComicSourceFiles($client) as $fileInfo) {
                // Match on the full path: the same file name can appear in
                // several folders, and the folder is what the tags come from.
                // The name is only a fallback for clients that predate this.
                if ($filePath !== null ? $fileInfo['path'] === $filePath : $fileInfo['name'] === $fileName) {
                    $targetFile = $fileInfo;
                    break;
                }
            }

            if (!$targetFile) {
                return $this->json(['error' => 'File not found in Dropbox'], Response::HTTP_NOT_FOUND);
            }

            if ($this->dropboxImport->isImported($targetFile, $this->dropboxImport->getImportedIndex($user))) {
                return $this->json(['error' => 'Comic is already imported'], Response::HTTP_BAD_REQUEST);
            }

            $comic = $this->dropboxImport->import($client, $user, $targetFile);

            $user->setDropboxLastSyncedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return $this->json([
                'message' => 'Comic imported successfully',
                'comic' => [
                    'id' => $comic->getId(),
                    'title' => $comic->getTitle(),
                    'tags' => array_merge([DropboxImportService::IMPORT_TAG], $targetFile['tags']),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Dropbox import failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['error' => 'Import failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/sync', name: 'dropbox_sync', methods: ['POST'])]
    public function sync(EntityManagerInterface $entityManager): Response
    {
        $user = $this->requireUser();

        if (!$user->hasDropboxConnection()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->dropboxClientFactory->createForUser($user);

            // The limit bounds the work a single request can do; the CLI sync
            // runs the same loop with its own limit.
            $result = $this->dropboxImport->syncUser($client, $user, $this->dropboxSyncLimit);

            $user->setDropboxLastSyncedAt(new \DateTimeImmutable());
            $entityManager->flush();

            $message = $result['failed'] > 0
                ? sprintf(
                    'Dropbox import partially completed: %d imported, %d failed.',
                    $result['newFiles'],
                    $result['failed']
                )
                : sprintf('Dropbox import completed: %d imported, 0 failed.', $result['newFiles']);

            return $this->json([
                'message' => $message,
                'newFiles' => $result['newFiles'],
                'failedFiles' => $result['failed'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Dropbox import failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['error' => 'Dropbox import failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
