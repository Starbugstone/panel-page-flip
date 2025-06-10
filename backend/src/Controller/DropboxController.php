<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Comic;
use App\Service\ComicService;
use Doctrine\ORM\EntityManagerInterface;
use Spatie\Dropbox\Client as DropboxClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Psr\Log\LoggerInterface;

#[Route('/api/dropbox')]
class DropboxController extends AbstractController
{
    private string $dropboxAppKey;
    private string $dropboxAppSecret;
    private string $dropboxRedirectUri;
    private SessionInterface $session;
    private HttpClientInterface $httpClient;
    private string $frontendBaseUrl;
    private string $comicsDirectory;
    private string $dropboxAppFolder;
    private LoggerInterface $logger;

    public function __construct(
        string $dropboxAppKey,
        string $dropboxAppSecret,
        string $dropboxRedirectUri,
        RequestStack $requestStack,
        HttpClientInterface $httpClient,
        string $frontendBaseUrl,
        string $comicsDirectory,
        string $dropboxAppFolder,
        LoggerInterface $logger
    ) {
        $this->dropboxAppKey = $dropboxAppKey;
        $this->dropboxAppSecret = $dropboxAppSecret;
        $this->dropboxRedirectUri = $dropboxRedirectUri;
        $this->session = $requestStack->getSession();
        $this->httpClient = $httpClient;
        $this->frontendBaseUrl = $frontendBaseUrl;
        $this->comicsDirectory = $comicsDirectory;
        $this->dropboxAppFolder = $dropboxAppFolder;
        $this->logger = $logger;
    }

    #[Route('/connect', name: 'dropbox_connect', methods: ['GET'])]
    public function connect(#[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // 1. Generate a secure random state for CSRF protection
        $state = bin2hex(random_bytes(16)); // 16 bytes = 32 hex characters
        $this->session->set('dropbox_oauth2_state', $state);
        $this->logger->debug('Dropbox OAuth state set in session', [
            'state' => $this->session->get('dropbox_oauth2_state'),
            'session_id' => $this->session->getId(),
            'user_id' => $user->getId()
        ]);

        // 2. Manually construct the Dropbox authorization URL
        $authUrlParams = http_build_query([
            'client_id' => $this->dropboxAppKey,
            'redirect_uri' => $this->dropboxRedirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline', // To get a refresh token
            'state' => $state,
            'scope' => 'files.content.read files.content.write account_info.read', // Required scopes for file operations
        ]);

        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . $authUrlParams;

        return new RedirectResponse($authUrl);
    }

    #[Route('/callback', name: 'dropbox_callback', methods: ['GET'])]
    public function callback(Request $request, EntityManagerInterface $entityManager, #[CurrentUser] ?User $user): Response
    {
        $this->logger->debug('Dropbox OAuth callback started', [
            'session_id' => $this->session->getId(),
            'session_data_count' => count($this->session->all()),
            'user_id' => $user?->getId()
        ]);
        
        if (!$user) {
            return $this->json(['error' => 'User not authenticated during callback'], Response::HTTP_UNAUTHORIZED);
        }

        $code = $request->query->get('code');
        $returnedState = $request->query->get('state');
        $savedState = $this->session->get('dropbox_oauth2_state');

        $this->logger->debug('Dropbox OAuth state validation', [
            'saved_state' => $savedState,
            'returned_state' => $returnedState,
            'session_id' => $this->session->getId(),
            'user_id' => $user->getId(),
            'states_match' => ($returnedState === $savedState)
        ]);
        
        if (empty($returnedState) || $returnedState !== $savedState) {
            $this->session->remove('dropbox_oauth2_state');
            $this->logger->warning('Dropbox OAuth state mismatch - possible CSRF attack', [
                'saved_state' => $savedState,
                'returned_state' => $returnedState,
                'user_id' => $user->getId()
            ]);
            return $this->json(['error' => 'Invalid OAuth state. CSRF attack suspected or session expired.'], Response::HTTP_UNAUTHORIZED);
        }
        $this->session->remove('dropbox_oauth2_state'); // State is valid, remove it

        if (!$code) {
            $this->logger->error('Dropbox OAuth callback missing authorization code', [
                'user_id' => $user->getId()
            ]);
            return $this->json(['error' => 'Dropbox authorization denied or failed. No code received.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // --- Manual Token Exchange using Symfony HttpClient ---
            $tokenUrl = 'https://api.dropboxapi.com/oauth2/token';
            $requestBody = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->dropboxRedirectUri,
                'client_id' => $this->dropboxAppKey,
                'client_secret' => $this->dropboxAppSecret,
            ];

            try {
                $response = $this->httpClient->request('POST', $tokenUrl, [
                    'body' => $requestBody,
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode === 200) {
                    $tokenData = $response->toArray(); // Decodes JSON response

                    $accessToken = $tokenData['access_token'] ?? null;
                    $refreshToken = $tokenData['refresh_token'] ?? null;
                    // Potentially also: $tokenData['uid'], $tokenData['account_id'], $tokenData['expires_in']

                    if (!$accessToken) {
                        $this->logger->error('Dropbox OAuth: No access token in 200 OK response', [
                            'response_data' => $tokenData,
                            'user_id' => $user->getId()
                        ]);
                        return $this->json(['error' => 'Dropbox connection succeeded but no access token was found in the response.'], Response::HTTP_INTERNAL_SERVER_ERROR);
                    }

                    $user->setDropboxAccessToken($accessToken);
                    $user->setDropboxRefreshToken($refreshToken);
                    $entityManager->persist($user);
                    $entityManager->flush();

                    $this->logger->info('Dropbox OAuth connection successful', [
                        'user_id' => $user->getId(),
                        'has_refresh_token' => !empty($refreshToken)
                    ]);

                    // Redirect to the frontend Dropbox sync page with a success indicator
                    $frontendSuccessUrl = rtrim($this->frontendBaseUrl, '/') . '/dropbox-sync?status=connected';
                    return new RedirectResponse($frontendSuccessUrl);

                } else {
                    $this->logger->error('Dropbox OAuth Error: Failed to get token', [
                        'status_code' => $statusCode,
                        'response_body' => $response->getContent(false),
                        'user_id' => $user->getId()
                    ]);
                    return $this->json(['error' => 'Failed to obtain Dropbox token. Status: ' . $statusCode, 'details' => $response->getContent(false)], Response::HTTP_BAD_GATEWAY);
                }

            } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
                $this->logger->error('Dropbox OAuth Transport Exception', [
                    'message' => $e->getMessage(),
                    'user_id' => $user->getId()
                ]);
                return $this->json(['error' => 'Network error while connecting to Dropbox: ' . $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
            } catch (\Throwable $e) { // Catch any other generic error during the process
                $this->logger->error('Dropbox OAuth Generic Exception', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $user->getId()
                ]);
                return $this->json(['error' => 'An unexpected error occurred during Dropbox connection: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $refreshToken = $tokenData['refresh_token'] ?? null; 
            // $userId = $tokenData['uid']; // Dropbox user ID
            // $accountId = $tokenData['account_id'];

            $user->setDropboxAccessToken($accessToken);
            $user->setDropboxRefreshToken($refreshToken);
            $entityManager->persist($user);
            $entityManager->flush();

            // Redirect to a frontend page indicating success
            // This URL should be configurable or a known route in your frontend app
            $frontendSuccessUrl = $this->getParameter('app.frontend_url') . '/dropbox-success'; // Example
            // return new RedirectResponse($frontendSuccessUrl);
            return $this->json(['message' => 'Dropbox connected successfully!']);

        } catch (\Exception $e) {
            $this->logger->error('Dropbox OAuth Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->getId()
            ]);
            return $this->json(['error' => 'Failed to connect Dropbox: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
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
                $client = new DropboxClient($user->getDropboxAccessToken());
                $account = $client->getAccountInfo();
                $dropboxUser = $account['name']['display_name'] ?? $account['email'] ?? 'Unknown';
                
                // Get last sync time from user metadata or a separate table if you implement it
                // For now, we'll use a placeholder
                $lastSync = null;
            } catch (\Exception $e) {
                // Token might be expired or invalid
                $connected = false;
            }
        }

        return $this->json([
            'connected' => $connected,
            'user' => $dropboxUser,
            'lastSync' => $lastSync
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
        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json(['message' => 'Dropbox disconnected successfully']);
    }

    #[Route('/files', name: 'dropbox_files', methods: ['GET'])]
    public function files(#[CurrentUser] ?User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->getDropboxAccessToken()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = new DropboxClient($user->getDropboxAccessToken());
            
            // Get existing comics for this user to check what's already synced
            $existingComics = $entityManager->getRepository(Comic::class)->findBy(['owner' => $user]);
            
            // Create a more robust way to check if files are synced
            // We'll check both filename and title similarity
            $existingFiles = array_map(function($comic) {
                return basename($comic->getFilePath());
            }, $existingComics);
            
            $existingTitles = array_map(function($comic) {
                return $comic->getTitle();
            }, $existingComics);

            // Get all files recursively with folder structure from the configured app folder
            $allFiles = $this->getAllDropboxFiles($client, $this->dropboxAppFolder);
            $files = [];

            foreach ($allFiles as $fileInfo) {
                // Check if file is synced using multiple methods
                $isSynced = $this->isDropboxFileSynced($fileInfo['name'], $existingFiles, $existingTitles);
                
                $files[] = [
                    'name' => $fileInfo['name'],
                    'path' => $fileInfo['path'],
                    'size' => $this->formatFileSize($fileInfo['size']),
                    'modified' => $fileInfo['modified'],
                    'tags' => $fileInfo['tags'],
                    'synced' => $isSynced
                ];
            }

            return $this->json(['files' => $files]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to fetch Dropbox files: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/import', name: 'dropbox_import_single', methods: ['POST'])]
    public function importSingle(Request $request, #[CurrentUser] ?User $user, EntityManagerInterface $entityManager, ComicService $comicService): Response
    {
        error_log("Import endpoint called with request: " . $request->getContent());
        
        if (!$user) {
            error_log("User not authenticated in import endpoint");
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->getDropboxAccessToken()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $fileName = $data['fileName'] ?? null;

        if (!$fileName) {
            return $this->json(['error' => 'fileName is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            error_log("Import request received for file: " . $fileName);
            
            $client = new DropboxClient($user->getDropboxAccessToken());
            
            // Get existing comics for this user
            $existingComics = $entityManager->getRepository(Comic::class)->findBy(['owner' => $user]);
            $existingFiles = array_map(function($comic) {
                return basename($comic->getFilePath());
            }, $existingComics);

            error_log("Existing files: " . implode(', ', $existingFiles));

            // Check if file is already imported
            if (in_array($fileName, $existingFiles)) {
                error_log("File already exists: " . $fileName);
                return $this->json(['error' => 'Comic is already imported'], Response::HTTP_BAD_REQUEST);
            }

            // Find the specific file in Dropbox
            $allFiles = $this->getAllDropboxFiles($client, $this->dropboxAppFolder);
            $targetFile = null;
            
            foreach ($allFiles as $fileInfo) {
                if (basename($fileInfo['path']) === $fileName) {
                    $targetFile = $fileInfo;
                    break;
                }
            }

            if (!$targetFile) {
                error_log("File not found in Dropbox: " . $fileName);
                return $this->json(['error' => 'File not found in Dropbox'], Response::HTTP_NOT_FOUND);
            }

            error_log("Target file found: " . json_encode($targetFile));

            $userDirectory = $this->comicsDirectory . '/' . $user->getId();
            
            // Ensure user directory exists
            if (!file_exists($userDirectory)) {
                mkdir($userDirectory, 0777, true);
            }

            // Create dropbox subdirectory
            $dropboxDirectory = $userDirectory . '/dropbox';
            if (!file_exists($dropboxDirectory)) {
                mkdir($dropboxDirectory, 0777, true);
            }

            // Test different path approaches
            error_log("Testing path encoding approaches...");
            
            // Test 1: Original path from metadata
            $originalPath = $targetFile['path'];
            error_log("Original path: " . $originalPath);
            
            // Test 2: URL-encoded path
            $encodedPath = rawurlencode($originalPath);
            error_log("URL-encoded path: " . $encodedPath);
            
            // Test 3: Just the path_lower field
            $lowerPath = $targetFile['path_lower'];
            error_log("Lower path: " . $lowerPath);
            
            // Test 4: Try to use the name and rebuild path
            $fileNameFromMeta = $targetFile['name'];
            $folderPath = dirname($originalPath);
            $rebuiltPath = $folderPath === '/' ? "/{$fileNameFromMeta}" : "{$folderPath}/{$fileNameFromMeta}";
            error_log("Rebuilt path: " . $rebuiltPath);
            
            // For now, let's try the original path first
            error_log("Attempting to download from path: " . $originalPath);
            
            // Try an alternative approach: get temporary link instead of direct download
            error_log("Attempting temporary link approach as workaround...");
            
            try {
                // Get temporary link first
                $tempLink = $client->getTemporaryLink($originalPath);
                error_log("Temporary link obtained: " . $tempLink);
                
                // Download using regular HTTP client
                $httpClient = \Symfony\Component\HttpClient\HttpClient::create();
                $response = $httpClient->request('GET', $tempLink);
                $fileContent = $response->getContent();
                
                error_log("File downloaded via temporary link, size: " . strlen($fileContent) . " bytes");
            } catch (\Exception $e) {
                error_log("Temporary link approach failed: " . get_class($e) . " - " . $e->getMessage());
                
                // Fallback to original direct download attempt
                error_log("Falling back to direct download...");
                $fileContent = $client->download($originalPath);
                error_log("Direct download successful, size: " . strlen($fileContent) . " bytes");
            }
            
            // Save to dropbox subdirectory
            $localPath = $dropboxDirectory . '/' . $fileName;
            file_put_contents($localPath, $fileContent);
            
            // Create a temporary UploadedFile object for the ComicService
            $tempFile = new UploadedFile(
                $localPath,
                $fileName,
                'application/zip',
                null,
                true // Test mode
            );
            
            // Extract title from filename
            $title = pathinfo($fileName, PATHINFO_FILENAME);
            $title = str_replace(['_', '-'], ' ', $title);
            $title = ucwords($title);
            
            // Create tags from folder structure + Dropbox tag
            $tags = array_merge(['Dropbox'], $targetFile['tags']);
            
            // Create comic entry
            $comic = $comicService->uploadComic(
                $tempFile,
                $user,
                $title,
                null, // author
                null, // publisher
                'Synced from Dropbox', // description
                $tags
            );

            return $this->json([
                'message' => 'Comic imported successfully',
                'comic' => [
                    'id' => $comic->getId(),
                    'title' => $comic->getTitle(),
                    'tags' => $tags
                ]
            ]);
        } catch (\Exception $e) {
            error_log("Import failed with exception: " . $e->getMessage());
            error_log("Exception trace: " . $e->getTraceAsString());
            return $this->json([
                'error' => 'Import failed: ' . $e->getMessage(),
                'details' => $e->getTraceAsString()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/sync', name: 'dropbox_sync', methods: ['POST'])]
    public function sync(#[CurrentUser] ?User $user, EntityManagerInterface $entityManager, ComicService $comicService): Response
    {
        if (!$user) {
            return $this->json(['error' => 'User not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->getDropboxAccessToken()) {
            return $this->json(['error' => 'Dropbox not connected'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $client = new DropboxClient($user->getDropboxAccessToken());
            
            // Get existing comics for this user
            $existingComics = $entityManager->getRepository(Comic::class)->findBy(['owner' => $user]);
            $existingFiles = array_map(function($comic) {
                return basename($comic->getFilePath());
            }, $existingComics);

            $newFiles = 0;
            $userDirectory = $this->comicsDirectory . '/' . $user->getId();
            
            // Ensure user directory exists
            if (!file_exists($userDirectory)) {
                mkdir($userDirectory, 0777, true);
            }

            // Create dropbox subdirectory
            $dropboxDirectory = $userDirectory . '/dropbox';
            if (!file_exists($dropboxDirectory)) {
                mkdir($dropboxDirectory, 0777, true);
            }

            // Get all files and folders recursively from the configured app folder
            $allFiles = $this->getAllDropboxFiles($client, $this->dropboxAppFolder);
            
            foreach ($allFiles as $fileInfo) {
                $fileName = basename($fileInfo['path']);
                
                if (!in_array($fileName, $existingFiles)) {
                    // Download the file from Dropbox
                    $fileContent = $client->download($fileInfo['path']);
                    
                    // Save to dropbox subdirectory
                    $localPath = $dropboxDirectory . '/' . $fileName;
                    file_put_contents($localPath, $fileContent);
                    
                    // Create a temporary UploadedFile object for the ComicService
                    $tempFile = new UploadedFile(
                        $localPath,
                        $fileName,
                        'application/zip',
                        null,
                        true // Test mode
                    );
                    
                    // Extract title from filename
                    $title = pathinfo($fileName, PATHINFO_FILENAME);
                    $title = str_replace(['_', '-'], ' ', $title);
                    $title = ucwords($title);
                    
                    // Create tags from folder structure + Dropbox tag
                    $tags = array_merge(['Dropbox'], $fileInfo['tags']);
                    
                    // Create comic entry
                    $comic = $comicService->uploadComic(
                        $tempFile,
                        $user,
                        $title,
                        null, // author
                        null, // publisher
                        'Synced from Dropbox', // description
                        $tags
                    );
                    
                    $newFiles++;
                }
            }

            return $this->json([
                'message' => 'Sync completed successfully',
                'newFiles' => $newFiles
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Sync failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Recursively get all CBZ files from Dropbox with their folder structure
     */
    private function getAllDropboxFiles($client, string $path = '/'): array
    {
        $allFiles = [];
        
        try {
            $response = $client->listFolder($path);
            
            foreach ($response['entries'] as $entry) {
                if ($entry['.tag'] === 'file' && strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) === 'cbz') {
                    // Extract folder path and convert to tags
                    $folderPath = trim(dirname($entry['path_display']), '/');
                    $tags = $this->convertPathToTags($folderPath);
                    
                    $allFiles[] = [
                        'path' => $entry['path_display'],
                        'path_lower' => $entry['path_lower'] ?? $entry['path_display'],
                        'name' => $entry['name'],
                        'size' => $entry['size'],
                        'modified' => $entry['client_modified'],
                        'tags' => $tags
                    ];
                } elseif ($entry['.tag'] === 'folder') {
                    // Recursively get files from subfolders
                    $subFiles = $this->getAllDropboxFiles($client, $entry['path_display']);
                    $allFiles = array_merge($allFiles, $subFiles);
                }
            }
        } catch (\Exception $e) {
            // Handle pagination or other errors
            error_log('Error listing Dropbox folder ' . $path . ': ' . $e->getMessage());
        }
        
        return $allFiles;
    }

    /**
     * Convert folder path to tags
     * Examples:
     * - "/Applications/StarbugStoneComics/superHero" -> ["Super Hero"]
     * - "/Applications/StarbugStoneComics/Manga/Anime" -> ["Manga", "Anime"]
     * - "/Applications/StarbugStoneComics/sci-fi/space_opera" -> ["Sci Fi", "Space Opera"]
     */
    private function convertPathToTags(string $path): array
    {
        if (empty($path) || $path === '.') {
            return [];
        }
        
        // Remove the app folder prefix from the path to get only the user's folder structure
        $appFolderPrefix = trim($this->dropboxAppFolder, '/') . '/';
        $relativePath = ltrim($path, '/');
        
        if (str_starts_with($relativePath, $appFolderPrefix)) {
            $relativePath = substr($relativePath, strlen($appFolderPrefix));
        }
        
        if (empty($relativePath)) {
            return [];
        }
        
        $folders = explode('/', $relativePath);
        $tags = [];
        
        foreach ($folders as $folder) {
            if (!empty($folder)) {
                // Convert camelCase and snake_case to readable format
                $tag = $this->formatFolderName($folder);
                if (!empty($tag)) {
                    $tags[] = $tag;
                }
            }
        }
        
        return $tags;
    }

    /**
     * Format folder name to readable tag
     * Examples:
     * - "superHero" -> "Super Hero"
     * - "sci-fi" -> "Sci Fi"
     * - "space_opera" -> "Space Opera"
     * - "MANGA" -> "Manga"
     */
    private function formatFolderName(string $folderName): string
    {
        // Replace underscores and hyphens with spaces
        $formatted = str_replace(['_', '-'], ' ', $folderName);
        
        // Split camelCase
        $formatted = preg_replace('/([a-z])([A-Z])/', '$1 $2', $formatted);
        
        // Clean up multiple spaces
        $formatted = preg_replace('/\s+/', ' ', $formatted);
        
        // Trim and convert to title case
        $formatted = trim($formatted);
        $formatted = ucwords(strtolower($formatted));
        
        return $formatted;
    }

    /**
     * Check if a Dropbox file has already been synced/imported
     * Uses multiple methods: exact filename match and title similarity
     */
    private function isDropboxFileSynced(string $dropboxFileName, array $existingFiles, array $existingTitles): bool
    {
        // Method 1: Exact filename match (for backwards compatibility)
        if (in_array($dropboxFileName, $existingFiles)) {
            return true;
        }
        
        // Method 2: Check if a comic with similar title exists
        // Extract title from Dropbox filename (remove extension and clean up)
        $dropboxTitle = pathinfo($dropboxFileName, PATHINFO_FILENAME);
        $dropboxTitle = str_replace(['_', '-'], ' ', $dropboxTitle);
        $dropboxTitle = ucwords(strtolower($dropboxTitle));
        
        // Normalize titles for comparison
        $normalizedDropboxTitle = $this->normalizeTitle($dropboxTitle);
        
        foreach ($existingTitles as $existingTitle) {
            $normalizedExistingTitle = $this->normalizeTitle($existingTitle);
            
            // Check for high similarity (allowing for minor differences)
            $similarity = 0;
            similar_text($normalizedDropboxTitle, $normalizedExistingTitle, $similarity);
            
            if ($similarity > 85) { // 85% similarity threshold
                return true;
            }
        }
        
        return false;
    }

    /**
     * Normalize title for comparison by removing special characters and extra spaces
     */
    private function normalizeTitle(string $title): string
    {
        // Convert to lowercase
        $normalized = strtolower($title);
        
        // Remove special characters and extra spaces
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = trim($normalized);
        
        return $normalized;
    }
}
