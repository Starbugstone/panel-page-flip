<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\ShareToken;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComicService $comicService,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
        #[Autowire('%public_shares_directory%')]
        private readonly string $publicSharesDirectory,
    ) {
    }

    public function delete(User $user): void
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)
            && $this->userRepository->countAdminsExcluding($user) === 0
        ) {
            throw new \DomainException('The last administrator account cannot be deleted.');
        }

        $quarantinedFiles = [];
        $publicCoverPaths = [];

        try {
            $this->entityManager->beginTransaction();

            foreach ($user->getComics()->toArray() as $comic) {
                array_push($quarantinedFiles, ...$this->comicService->quarantineComicFiles($comic));
            }

            foreach ($this->findRelatedShares($user) as $share) {
                if ($share->getPublicCoverPath()) {
                    $publicCoverPaths[] = rtrim($this->publicSharesDirectory, '/\\')
                        . DIRECTORY_SEPARATOR . basename($share->getPublicCoverPath());
                }
                $this->entityManager->remove($share);
            }

            foreach ($user->getCreatedTags()->toArray() as $tag) {
                foreach ($tag->getComics()->toArray() as $comic) {
                    $comic->removeTag($tag);
                }
                $this->entityManager->remove($tag);
            }

            $this->anonymiseAuditHistory($user);
            $this->entityManager->remove($user);
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->fileQuarantine->restore($quarantinedFiles);
            throw $exception;
        }

        $this->purgeFiles($quarantinedFiles, $publicCoverPaths);
    }

    /**
     * @return list<ShareToken>
     */
    private function findRelatedShares(User $user): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(ShareToken::class, 's')
            ->join('s.comic', 'comic')
            ->where('s.sharedByUser = :user')
            ->orWhere('LOWER(s.sharedWithEmail) = :email')
            ->orWhere('comic.owner = :user')
            ->setParameter('user', $user)
            ->setParameter('email', strtolower((string) $user->getEmail()))
            ->getQuery()
            ->getResult();
    }

    private function anonymiseAuditHistory(User $user): void
    {
        $email = (string) $user->getEmail();
        $name = $user->getName();

        foreach ($this->entityManager->getRepository(AdminAuditLog::class)->findAll() as $log) {
            if ($log->getAdminUser()?->getId() === $user->getId()) {
                $log->setAdminUser(null);
            }

            if ($log->getTargetType() === 'user' && $log->getTargetId() === $user->getId()) {
                $log->setTargetId(null);
            }

            $log->setPayload($this->redactPayload($log->getPayload(), $email, $name));
        }
    }

    /**
     * @param array<mixed>|null $payload
     * @return array<mixed>|null
     */
    private function redactPayload(?array $payload, string $email, ?string $name): ?array
    {
        if ($payload === null) {
            return null;
        }

        array_walk_recursive($payload, static function (&$value) use ($email, $name): void {
            if (!is_string($value)) {
                return;
            }
            $value = str_ireplace($email, '[redacted]', $value);
            if ($name !== null && $name !== '') {
                $value = str_replace($name, '[redacted]', $value);
            }
        });

        return $payload;
    }

    /**
     * @param list<array{originalPath: string, quarantinePath: string}> $quarantinedFiles
     * @param list<string> $publicCoverPaths
     */
    private function purgeFiles(array $quarantinedFiles, array $publicCoverPaths): void
    {
        try {
            $this->fileQuarantine->purge($quarantinedFiles);
        } catch (\Throwable $exception) {
            $this->logger->error('Account data was deleted, but quarantined files could not be purged.', [
                'exception' => $exception,
            ]);
        }

        foreach (array_unique($publicCoverPaths) as $path) {
            if (is_file($path) && !@unlink($path)) {
                $this->logger->error('Account data was deleted, but a public share cover could not be removed.', [
                    'path' => $path,
                ]);
            }
        }
    }
}
