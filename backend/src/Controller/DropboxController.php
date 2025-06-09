<?php

namespace App\Controller;

use App\Entity\User;
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

#[Route('/api/dropbox')]
class DropboxController extends AbstractController
{
    private string $dropboxAppKey;
    private string $dropboxAppSecret;
    private string $dropboxRedirectUri;
    private SessionInterface $session;
    private HttpClientInterface $httpClient;
    private string $frontendBaseUrl;

    public function __construct(
        string $dropboxAppKey,
        string $dropboxAppSecret,
        string $dropboxRedirectUri,
        RequestStack $requestStack,
        HttpClientInterface $httpClient,
        string $frontendBaseUrl
    ) {
        $this->dropboxAppKey = $dropboxAppKey;
        $this->dropboxAppSecret = $dropboxAppSecret;
        $this->dropboxRedirectUri = $dropboxRedirectUri;
        $this->session = $requestStack->getSession();
        $this->httpClient = $httpClient;
        $this->frontendBaseUrl = $frontendBaseUrl;
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
        dump(['State SET in session (connect)' => $this->session->get('dropbox_oauth2_state'), 'Session ID (connect)' => $this->session->getId()]);

        // 2. Manually construct the Dropbox authorization URL
        $authUrlParams = http_build_query([
            'client_id' => $this->dropboxAppKey,
            'redirect_uri' => $this->dropboxRedirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline', // To get a refresh token
            'state' => $state,
            // 'scope' => 'files.content.read files.content.write account_info.read', // Optional: specify scopes if needed
        ]);

        $authUrl = 'https://www.dropbox.com/oauth2/authorize?' . $authUrlParams;

        return new RedirectResponse($authUrl);
    }

    #[Route('/callback', name: 'dropbox_callback', methods: ['GET'])]
    public function callback(Request $request, EntityManagerInterface $entityManager, #[CurrentUser] ?User $user): Response
    {
        dump(['CALLBACK START - Session ID' => $this->session->getId(), 'CALLBACK START - All Session Data' => $this->session->all()]);
        if (!$user) {
            return $this->json(['error' => 'User not authenticated during callback'], Response::HTTP_UNAUTHORIZED);
        }

        $code = $request->query->get('code');
        $returnedState = $request->query->get('state');
        $savedState = $this->session->get('dropbox_oauth2_state');

        dump(['State FROM Session (callback)' => $savedState, 'State FROM Dropbox (callback)' => $returnedState, 'Session ID (callback before check)' => $this->session->getId()]);
        if (empty($returnedState) || $returnedState !== $savedState) {
            $this->session->remove('dropbox_oauth2_state');
            return $this->json(['error' => 'Invalid OAuth state. CSRF attack suspected or session expired.'], Response::HTTP_UNAUTHORIZED);
        }
        $this->session->remove('dropbox_oauth2_state'); // State is valid, remove it

        if (!$code) {
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
                        // Log error: Dropbox response did not contain an access token despite 200 OK.
                        // $this->get('logger')->error('Dropbox OAuth: No access token in 200 OK response.', ['response_data' => $tokenData]);
                        return $this->json(['error' => 'Dropbox connection succeeded but no access token was found in the response.'], Response::HTTP_INTERNAL_SERVER_ERROR);
                    }

                    $user->setDropboxAccessToken($accessToken);
                    $user->setDropboxRefreshToken($refreshToken);
                    $entityManager->persist($user);
                    $entityManager->flush();

                    // Redirect to the frontend Dropbox sync page with a success indicator
                    $frontendSuccessUrl = rtrim($this->frontendBaseUrl, '/') . '/dropbox-sync?status=connected';
                    return new RedirectResponse($frontendSuccessUrl);

                } else {
                    // Log error: $response->getContent(false) might contain error details from Dropbox
                    // $this->get('logger')->error('Dropbox OAuth Error: Failed to get token.', ['status_code' => $statusCode, 'response_body' => $response->getContent(false)]);
                    return $this->json(['error' => 'Failed to obtain Dropbox token. Status: ' . $statusCode, 'details' => $response->getContent(false)], Response::HTTP_BAD_GATEWAY);
                }

            } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
                // Log error: $this->get('logger')->error('Dropbox OAuth Transport Exception: ' . $e->getMessage());
                return $this->json(['error' => 'Network error while connecting to Dropbox: ' . $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
            } catch (\Throwable $e) { // Catch any other generic error during the process
                // Log error: $this->get('logger')->error('Dropbox OAuth Generic Exception: ' . $e->getMessage());
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

        } catch (\Spatie\Dropbox\Exceptions\BadRequest $e) {
            // Specific exception for bad requests during token exchange (e.g. invalid code)
            // Log error: $this->get('logger')->error('Dropbox OAuth BadRequest: ' . $e->getMessage());
            return $this->json(['error' => 'Dropbox connection failed (Bad Request): ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            // Log error: $this->get('logger')->error('Dropbox OAuth Error: ' . $e->getMessage());
            return $this->json(['error' => 'Failed to connect Dropbox: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
