<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\AppDataEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class UserSecretsSubscriber
{
    /** @var \WeakMap<User, array{access: ?string, refresh: ?string}> */
    private \WeakMap $logicalSnapshots;

    public function __construct(private readonly AppDataEncryptionService $encryption)
    {
        $this->logicalSnapshots = new \WeakMap(); // @phpstan-ignore assign.propertyType (WeakMap TValue is invariant)
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $user = $args->getObject();
        if ($user instanceof User) {
            $this->decryptAndSynchronize($user, $args->getObjectManager());
        }
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $user = $args->getObject();
        if (!$user instanceof User) {
            return;
        }

        $user->setDropboxAccessToken($this->encryption->encrypt($user->getDropboxAccessToken()));
        $user->setDropboxRefreshToken($this->encryption->encrypt($user->getDropboxRefreshToken()));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $user = $args->getObject();
        if (!$user instanceof User) {
            return;
        }

        $snapshot = $this->logicalSnapshots[$user] ?? [
            'access' => $user->getDropboxAccessToken(),
            'refresh' => $user->getDropboxRefreshToken(),
        ];
        $accessChanged = $user->getDropboxAccessToken() !== $snapshot['access'];
        $refreshChanged = $user->getDropboxRefreshToken() !== $snapshot['refresh'];

        if (!$accessChanged && !$refreshChanged) {
            return;
        }

        if ($accessChanged) {
            $user->setDropboxAccessToken($this->encryption->encrypt($user->getDropboxAccessToken()));
        }
        if ($refreshChanged) {
            $user->setDropboxRefreshToken($this->encryption->encrypt($user->getDropboxRefreshToken()));
        }

        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata(User::class),
            $user
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $user = $args->getObject();
        if ($user instanceof User) {
            $this->decryptAndSynchronize($user, $args->getObjectManager());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $user = $args->getObject();
        if ($user instanceof User) {
            $this->decryptAndSynchronize($user, $args->getObjectManager());
        }
    }

    private function decryptAndSynchronize(User $user, EntityManagerInterface $entityManager): void
    {
        $oid = spl_object_id($user);
        $access = $this->encryption->decrypt($user->getDropboxAccessToken());
        $refresh = $this->encryption->decrypt($user->getDropboxRefreshToken());

        $user->setDropboxAccessToken($access);
        $user->setDropboxRefreshToken($refresh);
        $snapshots = $this->logicalSnapshots;
        $snapshots[$user] = ['access' => $access, 'refresh' => $refresh];

        // The representation changed, not the logical value. Synchronizing the
        // UnitOfWork snapshot is what prevents a later unrelated flush from
        // treating plaintext-vs-ciphertext as a credential edit.
        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->setOriginalEntityProperty($oid, 'dropboxAccessToken', $access);
        $unitOfWork->setOriginalEntityProperty($oid, 'dropboxRefreshToken', $refresh);
    }
}
