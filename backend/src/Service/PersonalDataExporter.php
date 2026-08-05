<?php

namespace App\Service;

use App\Entity\ShareToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class PersonalDataExporter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $comics = [];
        foreach ($user->getComics() as $comic) {
            $comics[] = $comic->toArray();
        }

        $progress = [];
        foreach ($user->getReadingProgress() as $item) {
            $progress[] = [
                'comicId' => $item->getComic()?->getId(),
                'comicTitle' => $item->getComic()?->getTitle(),
                'currentPage' => $item->getCurrentPage(),
                'completed' => $item->isCompleted(),
                'lastReadAt' => $item->getLastReadAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        $tags = [];
        foreach ($user->getCreatedTags() as $tag) {
            $tags[] = [
                'id' => $tag->getId(),
                'name' => $tag->getName(),
                'hideFromLibrary' => $tag->hidesFromLibrary(),
                'createdAt' => $tag->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        $sentShares = $this->mapShares(
            $this->entityManager->getRepository(ShareToken::class)->findBy(
                ['sharedByUser' => $user],
                ['createdAt' => 'ASC'],
            ),
        );
        $receivedShares = $this->mapShares(
            $this->entityManager->createQueryBuilder()
                ->select('s')
                ->from(ShareToken::class, 's')
                ->where('LOWER(s.sharedWithEmail) = :email')
                ->setParameter('email', strtolower((string) $user->getEmail()))
                ->orderBy('s.createdAt', 'ASC')
                ->getQuery()
                ->getResult(),
        );

        return [
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'account' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'roles' => $user->getRoles(),
                'emailVerified' => $user->isEmailVerified(),
                'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'updatedAt' => $user->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                'dropboxConnected' => $user->getDropboxAccessToken() !== null
                    || $user->getDropboxRefreshToken() !== null,
                'dropboxLastSyncedAt' => $user->getDropboxLastSyncedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'comics' => $comics,
            'readingProgress' => $progress,
            'personalTags' => $tags,
            'shareInvitationsSent' => $sentShares,
            'shareInvitationsReceived' => $receivedShares,
        ];
    }

    /**
     * @param list<ShareToken> $shares
     * @return list<array<string, mixed>>
     */
    private function mapShares(array $shares): array
    {
        return array_map(
            static fn (ShareToken $share): array => [
                'comicId' => $share->getComic()->getId(),
                'comicTitle' => $share->getComic()->getTitle(),
                'senderUserId' => $share->getSharedByUser()->getId(),
                'recipientEmail' => $share->getSharedWithEmail(),
                'createdAt' => $share->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'expiresAt' => $share->getExpiresAt()->format(\DateTimeInterface::ATOM),
                'used' => $share->isIsUsed(),
            ],
            $shares,
        );
    }
}
