<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comic;
use App\Metadata\Provider\ProviderQuery;
use App\Security\ComicAccess;
use App\Security\Voter\ComicVoter;
use App\Service\ComicMetadataSuggestionService;
use App\Service\ComicTagSuggestionService;
use App\Service\MetadataProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Everything that asks an external provider about a comic.
 *
 * Kept apart from the comic's own CRUD because it is the only part of the API
 * that leaves the building: it has rate limits, a circuit breaker, a shared
 * quota between accounts, and failure modes that are somebody else's outage
 * rather than a bad request. Reading and writing a comic has none of that, and
 * should not have to be read past to find it.
 */
#[Route('/api/comics', name: 'api_comics_')]
class ComicMetadataController extends AbstractController
{
    use RequiresAuthenticatedUser;

    public function __construct(private readonly ComicAccess $comicAccess)
    {
    }

    /**
     * What could be filled in about this comic, and where each proposal came
     * from. Read-only by design: applying a suggestion is an edit, and goes
     * through the ordinary update route so it is authorised the same way.
     */
    #[Route('/{id}/metadata-suggestions', name: 'metadata_suggestions', methods: ['GET'])]
    public function metadataSuggestions(
        int $id,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        MetadataProviderRegistry $providers
    ): JsonResponse {
        $user = $this->requireUser();

        // Suggestions describe the comic, so seeing them needs the same right as
        // seeing the comic; acting on them needs the right to edit it.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::VIEW);

        return $this->json([
            'suggestions' => $suggestions->for($comic),
            // Tags the library already has that look like they belong to this
            // comic, and genres the file proposed. Nothing here creates a tag.
            // The owner's library, not the viewer's: these are the tags a save
            // would actually resolve against, so proposing anything else would
            // offer a choice the write path cannot honour.
            'tags' => $tagSuggestions->for($comic, $comic->getOwner() ?? $user),
            // Characters, teams, locations and story arcs. Shown as metadata and
            // never offered as tags — see ComicTagSuggestionService.
            'classification' => $comic->getClassification()->jsonSerialize() ?: null,
            // Named as the serializer names it. `origin` on its own meant two
            // different things in this API — which external record this comic
            // came from, and whose credential a lookup would spend — and only
            // one of those is the user's to see.
            'metadataOrigin' => $this->metadataOrigin($comic),
            // Which providers would answer this user, and why not when they
            // would not, so the editor can say something better than "no
            // results" before a search has even been run.
            'providers' => $providers->statusFor($user),
        ]);
    }

    /**
     * Records an external provider thinks might be this comic.
     *
     * A POST because the search is driven by the values currently in the edit
     * form, which the user may have accepted from a filename suggestion and not
     * yet saved. Making them save and reopen first was the flow break this
     * replaces.
     *
     * Those staged values are search hints and nothing more. What may be edited
     * is decided by the comic id and the voter, exactly as it is everywhere
     * else — a body cannot widen it.
     *
     * Editing the comic is what this leads to, so it takes the edit right
     * rather than the view right: a recipient a comic was shared with has no
     * business spending the installation's provider allowance on it.
     */
    #[Route('/{id}/metadata-candidates', name: 'metadata_candidates', methods: ['POST'])]
    public function metadataCandidates(
        int $id,
        Request $request,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $user = $this->requireUser();

        $comic = $this->comicAccess->requireComic($id, ComicVoter::EDIT);

        $data = \App\Http\JsonRequestDecoder::decode($request);

        $only = $data['provider'] ?? null;
        if ($only !== null && (!is_string($only) || $providers->get($only) === null)) {
            return $this->json(['message' => 'Unknown metadata provider.'], Response::HTTP_BAD_REQUEST);
        }

        // One person cannot spend the installation's whole hourly allowance
        // before anybody else opens an editor. Separate from the per-provider
        // ceiling, which protects the upstream account rather than the people
        // sharing it.
        if (!$metadataProviderUserLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->json(
                ['message' => 'You have run a lot of metadata searches recently. Try again shortly.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $query = ProviderQuery::staged($comic, is_array($data['query'] ?? null) ? $data['query'] : [], $suggestions->guess($comic));
        if ($query === null) {
            return $this->json([
                'message' => 'Give the comic a series or a title before searching.',
                'candidates' => [],
                'providers' => $providers->statusFor($user),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lookup = $providers->search($query, $user, $only);

        // Each candidate carries what accepting it would actually change, so
        // the review UI never has to work that out for itself and what it shows
        // matches what applying would do.
        //
        // The provider reporting is the reduced one: which providers would
        // answer this user, never which account was spent or why a shared
        // credential was refused.
        return $this->json([
            'query' => ['series' => $query->series, 'issueNumber' => $query->issueNumber, 'year' => $query->year],
            'candidates' => array_map(
                fn ($candidate): array => [
                    'candidate' => $candidate,
                    'suggestions' => $suggestions->fromCandidate($comic, $candidate),
                ],
                $lookup->candidates
            ),
            'providers' => $providers->publicResults($lookup->providers),
        ]);
    }

    /**
     * One exact provider record, in full.
     *
     * A search row carries a fraction of what a provider knows — Metron's issue
     * list has no publisher, description or genres at all — so the rest is
     * fetched when somebody picks a candidate rather than for every row of
     * every search, which would be a request per result against a rate limit.
     */
    #[Route('/{id}/metadata-record', name: 'metadata_record', methods: ['POST'])]
    public function metadataRecord(
        int $id,
        Request $request,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $comic = $this->comicAccess->requireComic($id, ComicVoter::EDIT);
        $data = \App\Http\JsonRequestDecoder::decode($request);

        return $this->respondWithRecord(
            $comic,
            is_string($data['provider'] ?? null) ? $data['provider'] : null,
            is_string($data['externalId'] ?? null) || is_int($data['externalId'] ?? null) ? (string) $data['externalId'] : null,
            $providers,
            $suggestions,
            $tagSuggestions,
            $metadataProviderUserLimiter
        );
    }

    /**
     * Ask the provider again about the record this comic was matched to.
     *
     * Produces suggestions, exactly as a first search does. A refresh that
     * quietly overwrote the fields would undo every edit the user has made
     * since — the whole point of remembering the external id is to make the
     * question cheap, not to make the answer authoritative.
     */
    #[Route('/{id}/metadata-refresh', name: 'metadata_refresh', methods: ['POST'])]
    public function metadataRefresh(
        int $id,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        // Authorised before anything is said about the comic's state: a 409
        // that only a matched comic can produce would otherwise answer "does
        // this id exist, and has its owner matched it" for a stranger.
        $comic = $this->comicAccess->requireComic($id, ComicVoter::EDIT);

        if ($comic->getMetadataProvider() === null) {
            return $this->json(
                ['message' => 'This comic has not been matched to a provider record yet. Search for one first.'],
                Response::HTTP_CONFLICT
            );
        }

        return $this->respondWithRecord(
            $comic,
            $comic->getMetadataProvider(),
            $comic->getMetadataExternalId(),
            $providers,
            $suggestions,
            $tagSuggestions,
            $metadataProviderUserLimiter
        );
    }

    /**
     * The shared body of "fetch one record and say what it would change".
     *
     * Both callers need the same failure vocabulary and the same response
     * shape; the only difference is where the record reference came from. The
     * comic arrives already authorised — taking the entity rather than an id is
     * what says so.
     */
    private function respondWithRecord(
        Comic $comic,
        ?string $providerKey,
        ?string $externalId,
        MetadataProviderRegistry $providers,
        ComicMetadataSuggestionService $suggestions,
        ComicTagSuggestionService $tagSuggestions,
        RateLimiterFactory $metadataProviderUserLimiter
    ): JsonResponse {
        $user = $this->requireUser();

        if ($providerKey === null || $externalId === null || $providers->get($providerKey) === null) {
            return $this->json(['message' => 'Name a provider and a record to look up.'], Response::HTTP_BAD_REQUEST);
        }

        // The same allowance the search consumes. A detail lookup is an upstream
        // request too, and a varying external id misses the cache, so leaving
        // this route unmetered would have let one account spend the whole
        // installation's quota through the fairness rule's back door.
        if (!$metadataProviderUserLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            return $this->json(
                ['message' => 'You have run a lot of metadata lookups recently. Try again shortly.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $result = $providers->detail($providerKey, $externalId, $user);
        $candidate = $result->candidates[0] ?? null;
        $status = $providers->publicResult($result);

        if ($candidate === null) {
            // The reduced reason, not the resolver's: a failed lookup must not
            // become a way to read back the shared credential's state.
            return $this->json([
                'message' => $status->reason ?? 'That record could not be read.',
                'provider' => $status,
            ], $result->isOk() ? Response::HTTP_NOT_FOUND : Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'candidate' => $candidate,
            'suggestions' => $suggestions->fromCandidate($comic, $candidate),
            // Genres from the record, offered beside the library's own tags and
            // selected by nobody until somebody selects them.
            'tags' => $tagSuggestions->for($comic, $comic->getOwner() ?? $user, $candidate->classification, $candidate->provider),
            'provider' => $status,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function metadataOrigin(Comic $comic): ?array
    {
        if ($comic->getMetadataProvider() === null) {
            return null;
        }

        return [
            'provider' => $comic->getMetadataProvider(),
            'externalId' => $comic->getMetadataExternalId(),
            'fetchedAt' => $comic->getMetadataFetchedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
