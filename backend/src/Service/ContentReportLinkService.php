<?php

namespace App\Service;

use App\Entity\Comic;
use App\Entity\ComicShare;
use App\Entity\ContentReport;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class ContentReportLinkService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function select(ContentReport $report, string $type, int $id, string $method = 'admin_selection'): void
    {
        match ($type) {
            'share' => $this->linkShare($report, $this->require(ComicShare::class, $id), $method),
            'comic' => $this->linkComic($report, $this->require(Comic::class, $id), $method),
            'user' => $this->linkUser($report, $this->require(User::class, $id), $method),
            default => throw new \DomainException('Select a valid report target type.'),
        };
    }

    public function linkShare(ContentReport $report, ComicShare $share, string $method): void
    {
        $report
            ->linkShare($share)
            ->linkComic($share->getComic())
            ->linkUser($share->getOwner())
            ->snapshotTarget($method);
    }

    public function linkComic(ContentReport $report, Comic $comic, string $method): void
    {
        $share = $report->getLinkedShare();
        if ($share !== null && $share->getComic()?->getId() !== $comic->getId()) {
            $report->linkShare(null);
        }
        $report
            ->linkComic($comic)
            ->linkUser($comic->getOwner())
            ->snapshotTarget($method);
    }

    public function linkUser(ContentReport $report, User $user, string $method): void
    {
        $impliedOwner = $report->getLinkedShare()?->getOwner() ?? $report->getLinkedComic()?->getOwner();
        if ($impliedOwner !== null && $impliedOwner->getId() !== $user->getId()) {
            throw new \DomainException('The selected user does not own the linked comic or share.');
        }
        $report->linkUser($user)->snapshotTarget($method);
    }

    /**
     * Take the report back to having no target at all.
     *
     * The snapshot goes with it. A snapshot exists to remember a target that
     * was deleted, so leaving one behind would keep the queue pointing at the
     * record an admin has just said is the wrong one. Reports are auto-linked
     * at submission from the reference the reporter typed, so being wrong is
     * ordinary and has to be reversible.
     */
    public function unlink(ContentReport $report, string $method = 'admin_cleared'): void
    {
        $report
            ->linkShare(null)
            ->linkComic(null)
            ->linkUser(null)
            ->snapshotTarget($method);
    }

    public function assertCanonical(ContentReport $report): void
    {
        $share = $report->getLinkedShare();
        $comic = $report->getLinkedComic();
        if ($share !== null && $comic === null && $share->getComic() !== null) {
            $report->linkComic($share->getComic());
            $comic = $share->getComic();
        } elseif ($share !== null && $share->getComic()?->getId() !== $comic?->getId()) {
            throw new \DomainException('The linked share does not belong to the linked comic.');
        }
        $owner = $share?->getOwner() ?? $comic?->getOwner();
        $user = $report->getLinkedUser();
        if ($owner !== null && $user === null) {
            $report->linkUser($owner)->snapshotTarget($report->getResolutionMethod() ?? 'canonicalized');
        } elseif ($owner !== null && $owner->getId() !== $user?->getId()) {
            throw new \DomainException('The linked user does not own the linked comic or share.');
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function require(string $class, int $id): object
    {
        return $this->entityManager->getRepository($class)->find($id)
            ?? throw new \DomainException('The selected report target could not be found.');
    }
}
