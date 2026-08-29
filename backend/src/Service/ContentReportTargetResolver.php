<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\User;
use App\Enum\ShareCodeType;
use App\Repository\ComicRepository;
use App\Repository\ComicShareRepository;
use App\Repository\ShareClaimCodeRepository;
use App\Repository\ShareInvitationTokenRepository;
use App\Repository\UserRepository;

/**
 * Privately identifies possible case targets. It performs no network requests,
 * changes no restriction state and is never exposed through the public API.
 */
final class ContentReportTargetResolver
{
    public function __construct(
        private readonly ShareInvitationTokenRepository $invitationTokens,
        private readonly ShareClaimCodeRepository $claimCodes,
        private readonly ComicRepository $comics,
        private readonly ComicShareRepository $shares,
        private readonly UserRepository $users,
        private readonly PublicUrl $publicUrl,
    ) {
    }

    /** @return array{type: string, id: int, method: string}|null */
    public function exactTarget(ContentReport $report): ?array
    {
        $reference = $report->getReportedReference();
        return match ($report->getReferenceType()) {
            ContentReport::REFERENCE_INVITATION_URL => $this->invitationTarget($reference),
            ContentReport::REFERENCE_SHARING_CODE => $this->contentCodeTarget($reference),
            ContentReport::REFERENCE_USER_CODE => $this->userCodeTarget($reference),
            ContentReport::REFERENCE_PANEL_URL => $this->panelUrlTarget($reference),
            default => null,
        };
    }

    /**
     * @return array{status: string, method: string|null, candidates: list<array<string, mixed>>}
     */
    public function resolve(ContentReport $report, ?string $adminQuery = null): array
    {
        $exact = $this->exactTarget($report);
        if ($exact !== null && trim((string) $adminQuery) === '') {
            return ['status' => 'exact', 'method' => $exact['method'], 'candidates' => [$this->candidate($exact['type'], $exact['id'])]];
        }

        $candidates = $exact !== null ? [$this->candidate($exact['type'], $exact['id'])] : [];
        if ($report->getReferenceType() === ContentReport::REFERENCE_SHARING_CODE) {
            $parsed = SharingCodeFormat::parse($report->getReportedReference());
            $code = $parsed?->type->isContentCode() === true ? $this->claimCodes->findByParsedCode($parsed) : null;
            if ($code !== null && $parsed?->type === ShareCodeType::GROUP) {
                foreach ($code->getComics() as $comic) {
                    $candidates[] = $this->comicCandidate($comic, 'group_code');
                }
            }
        }

        $queries = array_filter(array_unique([
            trim((string) $adminQuery),
            trim((string) $report->getReportedContentTitle()),
            trim((string) $report->getReportedAccountReference()),
            in_array($report->getReferenceType(), [ContentReport::REFERENCE_COMIC, ContentReport::REFERENCE_ACCOUNT], true)
                ? trim($report->getReportedReference()) : '',
        ]), static fn (string $value): bool => mb_strlen($value) >= 2);

        foreach ($queries as $query) {
            foreach ($this->comics->searchForContentReport($query, 10) as $comic) {
                $candidates[] = $this->comicCandidate($comic, 'search');
            }
            foreach ($this->users->searchForContentReport($query, 10) as $user) {
                $candidates[] = $this->userCandidate($user, 'search');
            }
        }

        $unique = [];
        foreach ($candidates as $candidate) {
            $unique[$candidate['type'].':'.$candidate['id']] = $candidate;
        }
        $candidates = array_slice(array_values($unique), 0, 20);
        return [
            'status' => $candidates === [] ? 'none' : 'candidates',
            'method' => $candidates === [] ? null : 'search',
            'candidates' => $candidates,
        ];
    }

    /** @return array{type: string, id: int, method: string}|null */
    private function invitationTarget(string $reference): ?array
    {
        $parts = parse_url($reference);
        if (!is_array($parts) || !$this->publicUrl->hasSameOrigin($reference)) return null;
        if (!preg_match('#^/share/invitation/([A-Za-z0-9]+)$#', (string) ($parts['path'] ?? ''), $matches)) return null;
        $share = $this->invitationTokens->findByPlaintext($matches[1])?->getComicShare();
        return $share?->getId() === null ? null : ['type' => 'share', 'id' => $share->getId(), 'method' => 'invitation_url'];
    }

    /** @return array{type: string, id: int, method: string}|null */
    private function contentCodeTarget(string $reference): ?array
    {
        $parsed = SharingCodeFormat::parse($reference);
        if ($parsed === null || !$parsed->type->isContentCode()) return null;
        $code = $this->claimCodes->findByParsedCode($parsed);
        if ($code === null || $parsed->type !== ShareCodeType::COMIC || $code->getComics()->count() !== 1) return null;
        $comic = $code->getComics()->first();
        return $comic instanceof Comic && $comic->getId() !== null
            ? ['type' => 'comic', 'id' => $comic->getId(), 'method' => 'comic_code']
            : null;
    }

    /** @return array{type: string, id: int, method: string}|null */
    private function userCodeTarget(string $reference): ?array
    {
        $parsed = SharingCodeFormat::parse($reference);
        if ($parsed === null || $parsed->type !== ShareCodeType::USER) return null;
        $user = $this->users->findOneBy(['userCode' => $parsed->token]);
        return $user?->getId() === null ? null : ['type' => 'user', 'id' => $user->getId(), 'method' => 'user_code'];
    }

    /** @return array{type: string, id: int, method: string}|null */
    private function panelUrlTarget(string $reference): ?array
    {
        $parts = parse_url($reference);
        if (!is_array($parts) || !$this->publicUrl->hasSameOrigin($reference)) return null;
        if (!preg_match('#^/read/(\d+)$#', (string) ($parts['path'] ?? ''), $matches)) return null;
        $comic = $this->comics->find((int) $matches[1]);
        return $comic?->getId() === null ? null : ['type' => 'comic', 'id' => $comic->getId(), 'method' => 'panel_url'];
    }

    /** @return array<string, mixed> */
    private function candidate(string $type, int $id): array
    {
        return match ($type) {
            'comic' => $this->comicCandidate($this->comics->find($id) ?? throw new \LogicException(), 'exact'),
            'user' => $this->userCandidate($this->users->find($id) ?? throw new \LogicException(), 'exact'),
            'share' => $this->shareCandidate($this->shares->find($id) ?? throw new \LogicException(), 'exact'),
            default => throw new \LogicException(),
        };
    }

    /** @return array<string, mixed> */
    private function comicCandidate(Comic $comic, string $source): array
    {
        return ['type' => 'comic', 'id' => $comic->getId(), 'source' => $source, 'title' => $comic->getTitle(), 'owner' => $this->owner($comic->getOwner())];
    }

    /** @return array<string, mixed> */
    private function userCandidate(User $user, string $source): array
    {
        return ['type' => 'user', 'id' => $user->getId(), 'source' => $source, 'name' => $user->getName(), 'email' => $user->getEmail()];
    }

    /** @return array<string, mixed> */
    private function shareCandidate(ComicShare $share, string $source): array
    {
        return ['type' => 'share', 'id' => $share->getId(), 'source' => $source, 'status' => $share->getStatus(), 'title' => $share->getComic()?->getTitle() ?? $share->getComicTitleSnapshot(), 'owner' => $this->owner($share->getOwner())];
    }

    /** @return array{id: int|null, name: string}|null */
    private function owner(?User $owner): ?array
    {
        return $owner ? ['id' => $owner->getId(), 'name' => $owner->getName()] : null;
    }
}
