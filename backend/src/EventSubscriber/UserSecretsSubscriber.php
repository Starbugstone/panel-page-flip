<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\AppDataEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
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
class UserSecretsSubscriber
{
    public function __construct(private readonly AppDataEncryptionService $encryption)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->decryptUser($args->getObject());
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->encryptUser($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $user = $args->getObject();
        if (!$user instanceof User) {
            return;
        }

        $this->encryptUser($user);
        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata(User::class),
            $user
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->decryptUser($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->decryptUser($args->getObject());
    }

    private function encryptUser(object $object): void
    {
        if (!$object instanceof User) {
            return;
        }

        $object->setDropboxAccessToken($this->encryption->encrypt($object->getDropboxAccessToken()));
        $object->setDropboxRefreshToken($this->encryption->encrypt($object->getDropboxRefreshToken()));
    }

    private function decryptUser(object $object): void
    {
        if (!$object instanceof User) {
            return;
        }

        $object->setDropboxAccessToken($this->encryption->decrypt($object->getDropboxAccessToken()));
        $object->setDropboxRefreshToken($this->encryption->decrypt($object->getDropboxRefreshToken()));
    }
}
