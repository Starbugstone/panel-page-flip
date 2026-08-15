<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\MetadataProviderConfiguration;
use App\Entity\UserMetadataCredential;
use App\Service\AppDataEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Provider credentials are encrypted at rest, the same way user tokens are.
 *
 * Covers both the installation's shared credentials and each user's own, which
 * are the same kind of secret held for a different party.
 */
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
class MetadataProviderSecretsSubscriber
{
    public function __construct(private readonly AppDataEncryptionService $encryption)
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $this->decrypt($args->getObject());
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->encrypt($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->holdsSecrets($entity)) {
            return;
        }

        $this->encrypt($entity);
        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata($entity::class),
            $entity
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->decrypt($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->decrypt($args->getObject());
    }

    private function encrypt(object $object): void
    {
        if ($object instanceof MetadataProviderConfiguration) {
            $object->setMetronToken($this->encryption->encrypt($object->getMetronToken()));
            $object->setComicVineApiKey($this->encryption->encrypt($object->getComicVineApiKey()));
        } elseif ($object instanceof UserMetadataCredential) {
            $object->setMetronToken($this->encryption->encrypt($object->getMetronToken()));
            $object->setComicVineApiKey($this->encryption->encrypt($object->getComicVineApiKey()));
        }
    }

    private function decrypt(object $object): void
    {
        if ($object instanceof MetadataProviderConfiguration) {
            $object->setMetronToken($this->encryption->decrypt($object->getMetronToken()));
            $object->setComicVineApiKey($this->encryption->decrypt($object->getComicVineApiKey()));
        } elseif ($object instanceof UserMetadataCredential) {
            $object->setMetronToken($this->encryption->decrypt($object->getMetronToken()));
            $object->setComicVineApiKey($this->encryption->decrypt($object->getComicVineApiKey()));
        }
    }

    private function holdsSecrets(object $object): bool
    {
        return $object instanceof MetadataProviderConfiguration || $object instanceof UserMetadataCredential;
    }
}
