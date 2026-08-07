<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\DropboxClientFactory;
use App\Service\DropboxImportService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/api/dropbox')]
class DropboxController extends AbstractController
{
    private SessionInterface $session;

    public function __construct(
        private readonly string $dropboxAppKey,
        private readonly string $dropboxAppSecret,
        private readonly string $dropboxRedirectUri,
        RequestStack $requestStack,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $frontendBaseUrl,
        private readonly DropboxClientFactory $dropboxClientFactory,
        private readonly DropboxImportService $dropboxImport,
        private readonly int $dropboxSyncLimit
    ) {
        $this->session = $requestStack->getSession();
    }

    #[Route('/connect', name: 'dropbox_connect', methods: ['GET'])]
    public function connect(#[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Random state, echoed back by Dropbox, to protect the callback from CSRF.
        $state = bin2hex(random_bytes(16));
        $this->session->set('dropbox_oauth2_state', $state);
        $this->logger->debug('Dropbox OAuth state created.', ['session_id' => hash('sha256', $this->session->getId())]);

        $authUrlParams = http_build_query([
            'client_id' => $this->dropboxAppKey,
            'redirect_uri' => $this->dropboxRedirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline', // To get a refresh token
            'state' => $state,
            'scope' => 'files.content.read files.content.write account_info.read',
        ]);

        return new RedirectResponse('https://www.dropbox.com/oauth2/authorize?' . $authUrlParams);
    }

    #[Route('/callback', name: 'dropbox_callback', methods: ['GET'])]
    public function callback(Request $request, EntityManagerInterface $entityManager, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated during callback'], Response::HTTP_UNAUTHORIZED);
        }

        $code = $request->query->get('code');
        $returnedState = $request->query->get('state');
        $savedState = $this->session->get('dropbox_oauth2_state');
        $this->session->remove('dropbox_oauth2_state');

        if (empty($returnedState) || !is_string($savedState) || !hash_equals($savedState, $returnedState)) {
            $this->logger->warning('Dropbox OAuth state mismatch.', ['user_id' => $user->getId()]);
            return $this->json(['error' => 'Invalid OAuth state. CSRF attack suspected or session expired.'], Response::HTTP_UNAUTHORIZED);
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
                    'client_id' => $this->dropboxAppKey,
                    'client_secret' => $this->dropboxAppSecret,
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
            // Recorded here so the very first API call after connecting uses
            // the token it was just given instead of immediately refreshing it.
            $user->setDropboxTokenExpiresAt(
                $this->dropboxClientFactory->expiryFrom($tokenData['expires_in'] ?? null)
            );
            $entityManager->flush();

            return new RedirectResponse(rtrim($this->frontendBaseUrl, '/') . '/dropbox-sync?status=connected');
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Network error during Dropbox token exchange.', ['exception' => $e]);
            return $this->json(['error' => 'Network error while connecting to Dropbox.'], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            $this->logger->error('Dropbox connection failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['error' => 'Failed to connect Dropbox.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/status', name: 'dropbox_status', methods: ['GET'])]
    public function status(#[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $connected = !empty($user->getDropboxAccessToken());
        $dropboxUser = null;
        $lastSync = null;

        if ($connected) {
            try {
                $account = $this->dropboxClientFactory->createForUser($user)->getAccountInfo();
                $dropboxUser = $account['name']['display_name'] ?? $account['email'] ?? 'Unknown';
                $lastSync = $user->getDropboxLastSyncedAt()?->format('c');
            } catch (\Throwable $e) {
                // Token expired or revoked: report as disconnected so the UI offers to reconnect.
                $this->logger->info('Dropbox status check failed, treating account as disconnected.', [
                    'user_id' => $user->getId(),
                    'exception' => $e,
                ]);
                // The stored token did not work, whatever its recorded expiry
                // claimed. Clearing that claim means the next call refreshes
                // rather than presenting the same dead token again — which is
                // what recovers a grant Dropbox retired early.
                $this->dropboxClientFactory->invalidateAccessToken($user);
                $connected = false;
            }
        }

        return $this->json([
            'connected' => $connected,
            'user' => $dropboxUser,
            'lastSync' => $lastSync,
        ]);
    }

    #[Route('/disconnect', name: 'dropbox_disconnect', methods: ['POST'])]
    public function disconnect(#[CurrentUser] ?User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $user->setDropboxAccessToken(null);
        $user->setDropboxRefreshToken(null);
        $entityManager->flush();

        return $this->json(['message' => 'Dropbox disconnected successfully']);
    }

    #[Route('/files', name: 'dropbox_files', methods: ['GET'])]
    public function files(#[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->getDropboxAccessToken()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->dropboxClientFactory->createForUser($user);
            $importedIndex = $this->dropboxImport->getImportedIndex($user);

            $files = [];
            foreach ($this->dropboxImport->listCbzFiles($client) as $fileInfo) {
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
    public function importSingle(Request $request, #[CurrentUser] ?User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->getDropboxAccessToken()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $filePath = is_array($data) ? ($data['path'] ?? null) : null;
        $fileName = is_array($data) ? ($data['fileName'] ?? null) : null;
        $filePath = is_string($filePath) && $filePath !== '' ? $filePath : null;
        $fileName = is_string($fileName) && $fileName !== '' ? $fileName : null;

        if ($filePath === null && $fileName === null) {
            return $this->json(['error' => 'path is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->dropboxClientFactory->createForUser($user);

            $targetFile = null;
            foreach ($this->dropboxImport->listCbzFiles($client) as $fileInfo) {
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
    public function sync(#[CurrentUser] ?User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->getDropboxAccessToken()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = $this->dropboxClientFactory->createForUser($user);

            // The limit bounds the work a single request can do; the CLI sync
            // runs the same loop with its own limit.
            $result = $this->dropboxImport->syncUser($client, $user, $this->dropboxSyncLimit);

            $user->setDropboxLastSyncedAt(new \DateTimeImmutable());
            $entityManager->flush();

            return $this->json([
                'message' => 'Sync completed successfully',
                'newFiles' => $result['newFiles'],
                'failedFiles' => $result['failed'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Dropbox sync failed.', ['user_id' => $user->getId(), 'exception' => $e]);
            return $this->json(['error' => 'Sync failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
