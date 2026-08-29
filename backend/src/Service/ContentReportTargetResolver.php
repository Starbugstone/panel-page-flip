<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\User;
use App\Enum\ReportedReferenceType;
use App\Enum\ShareCodeType;
use App\Repository\ComicRepository;
use App\Repository\ShareClaimCodeRepository;
use App\Repository\ShareInvitationTokenRepository;
use App\Repository\UserRepository;
use App\Service\Pagination\LikePattern;

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
        private readonly UserRepository $users,
        private readonly PublicUrl $publicUrl,
        private readonly ContentReportTargetPresenter $presenter,
    ) {
    }

    /** @return array{type: string, id: int, method: string}|null */
    public function exactTarget(ContentReport $report): ?array
    {
        $match = $this->exactMatch($report);
        if ($match === null) {
            return null;
        }

        return ['type' => $this->presenter->type($match['entity']), 'id' => (int) $match['entity']->getId(), 'method' => $match['method']];
    }

    /**
     * The record the reporter's own reference identifies, if it identifies one.
     *
     * Carries the entity rather than its id, because every caller needs the
     * record itself and looking it up again by the id it was just read from is
     * a round trip to answer a question already answered.
     *
     * @return array{entity: Comic|User|ComicShare, method: string}|null
     */
    private function exactMatch(ContentReport $report): ?array
    {
        $reference = $report->getReportedReference();
        // No default arm: a new reference type has to say here whether it can be
        // resolved, or PHPStan fails the build. Silently returning null would
        // file every report of that kind permanently unlinked.
        return match ($report->referenceKind()) {
            ReportedReferenceType::InvitationUrl => $this->invitationTarget($reference),
            ReportedReferenceType::SharingCode => $this->contentCodeTarget($reference),
            ReportedReferenceType::UserCode => $this->userCodeTarget($reference),
            ReportedReferenceType::PanelUrl => $this->panelUrlTarget($reference),
            ReportedReferenceType::Account, ReportedReferenceType::Comic, ReportedReferenceType::Other => null,
        };
    }

    /**
     * @return array{status: string, method: string|null, candidates: list<array<string, mixed>>}
     */
    public function resolve(ContentReport $report, ?string $adminQuery = null): array
    {
        $exact = $this->exactMatch($report);
        if ($exact !== null && trim((string) $adminQuery) === '') {
            return ['status' => 'exact', 'method' => $exact['method'], 'candidates' => [$this->presenter->candidate($exact['entity'], 'exact')]];
        }

        $candidates = $exact !== null ? [$this->presenter->candidate($exact['entity'], 'exact')] : [];
        if ($report->referenceKind() === ReportedReferenceType::SharingCode) {
            $parsed = SharingCodeFormat::parse($report->getReportedReference());
            if ($parsed?->type === ShareCodeType::GROUP) {
                foreach ($this->claimCodes->findByParsedCode($parsed)?->getComics() ?? [] as $comic) {
                    $candidates[] = $this->presenter->candidate($comic, 'group_code');
                }
            }
        }

        $queries = array_filter(array_unique([
            trim((string) $adminQuery),
            trim((string) $report->getReportedContentTitle()),
            trim((string) $report->getReportedAccountReference()),
            $report->referenceKind()->isSearchableText() ? trim($report->getReportedReference()) : '',
        ]), static fn (string $value): bool => mb_strlen($value) >= LikePattern::MIN_TERM_LENGTH);

        foreach ($queries as $query) {
            foreach ($this->comics->searchForContentReport($query, 10) as $comic) {
                $candidates[] = $this->presenter->candidate($comic, 'search');
            }
            foreach ($this->users->searchForContentReport($query, 10) as $user) {
                $candidates[] = $this->presenter->candidate($user, 'search');
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

    /** @return array{entity: ComicShare, method: string}|null */
    private function invitationTarget(string $reference): ?array
    {
        $matches = $this->publicUrl->matchPath($reference, ContentReport::PATH_INVITATION_URL);
        if ($matches === null) return null;
        $share = $this->invitationTokens->findByPlaintext($matches[1])?->getComicShare();
        return $share?->getId() === null ? null : ['entity' => $share, 'method' => 'invitation_url'];
    }

    /** @return array{entity: Comic, method: string}|null */
    private function contentCodeTarget(string $reference): ?array
    {
        $parsed = SharingCodeFormat::parse($reference);
        if ($parsed?->type !== ShareCodeType::COMIC) return null;
        $code = $this->claimCodes->findByParsedCode($parsed);
        if ($code === null || $code->getComics()->count() !== 1) return null;
        $comic = $code->getComics()->first();
        return $comic instanceof Comic && $comic->getId() !== null
            ? ['entity' => $comic, 'method' => 'comic_code']
            : null;
    }

    /** @return array{entity: User, method: string}|null */
    private function userCodeTarget(string $reference): ?array
    {
        $parsed = SharingCodeFormat::parse($reference);
        if ($parsed === null || $parsed->type !== ShareCodeType::USER) return null;
        $user = $this->users->findOneBy(['userCode' => $parsed->token]);
        return $user?->getId() === null ? null : ['entity' => $user, 'method' => 'user_code'];
    }

    /** @return array{entity: Comic, method: string}|null */
    private function panelUrlTarget(string $reference): ?array
    {
        $matches = $this->publicUrl->matchPath($reference, ContentReport::PATH_PANEL_URL);
        if ($matches === null) return null;
        $comic = $this->comics->find((int) $matches[1]);
        return $comic?->getId() === null ? null : ['entity' => $comic, 'method' => 'panel_url'];
    }

}
