<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\ComicShare;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final class AccountDeletionService
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly ComicService $comicService,
        private readonly ComicShareService $shareService,
        private readonly ComicShareRepository $shareRepository,
        private readonly FileQuarantineService $fileQuarantine,
        private readonly PendingFileDeletionService $pendingFileDeletion,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function delete(User $user): void
    {
        $quarantinedFiles = [];
        $pendingFileDeletions = [];
        $entityManager = $this->entityManager();
        // Avoid nesting commits when a caller (or the test suite) already owns
        // the transaction — a nested commit would escape DAMA's rollback wrap.
        $ownsTransaction = !$entityManager->getConnection()->isTransactionActive();

        try {
            if ($ownsTransaction) {
                $entityManager->beginTransaction();
            }

            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $this->userRepository->lockAdministrators();
                if ($this->userRepository->countAdminsExcluding($user) === 0) {
                    throw new \DomainException('The last administrator account cannot be deleted.');
                }
            }

            foreach ($user->getComics()->toArray() as $comic) {
                // Recipients of this comic are told it went away with its
                // owner's account rather than finding it silently missing.
                $this->shareService->tombstoneSharesForComic($comic, ComicShare::REASON_OWNER_ACCOUNT_DELETED);
                array_push($quarantinedFiles, ...$this->comicService->quarantineComicFiles($comic));
            }

            foreach ($this->shareRepository->findAllInvolving($user) as $share) {
                // Anything addressed to this user holds their email address, so
                // it goes; the tombstones they left behind for other people stay
                // but stop naming them.
                if ($this->isRecipient($share, $user)) {
                    $entityManager->remove($share);
                    continue;
                }

                $share->anonymiseOwner();
            }

            foreach ($user->getCreatedTags()->toArray() as $tag) {
                foreach ($tag->getComics()->toArray() as $comic) {
                    $comic->removeTag($tag);
                }
                $entityManager->remove($tag);
            }

            $pendingFileDeletions = $this->pendingFileDeletion->queue(
                array_column($quarantinedFiles, 'quarantinePath')
            );
            $this->anonymiseAuditHistory($user);
            $entityManager->remove($user);
            $entityManager->flush();
            if ($ownsTransaction) {
                $entityManager->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }
            $this->pendingFileDeletion->cancel($pendingFileDeletions);
            $this->fileQuarantine->restore($quarantinedFiles);
            throw $exception;
        }

        if ($pendingFileDeletions !== []) {
            $this->pendingFileDeletion->purge($pendingFileDeletions);
        }
    }

    private function isRecipient(ComicShare $share, User $user): bool
    {
        return $share->getRecipientUser()?->getId() === $user->getId()
            || $share->getRecipientEmailNormalized() === ComicShare::normaliseEmail((string) $user->getEmail());
    }

    private function anonymiseAuditHistory(User $user): void
    {
        $email = (string) $user->getEmail();
        $name = $user->getName();

        foreach ($this->entityManager()->getRepository(AdminAuditLog::class)->findAll() as $log) {
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
                $value = str_ireplace($name, '[redacted]', $value);
            }
        });

        return $payload;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Expected a Doctrine ORM entity manager.');
        }

        return $entityManager;
    }
}
