<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\MetadataProviderConfiguration;
use App\Service\AppDataEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/** Provider credentials are encrypted at rest, the same way user tokens are. */
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
        $configuration = $args->getObject();
        if (!$configuration instanceof MetadataProviderConfiguration) {
            return;
        }

        $this->encrypt($configuration);
        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata(MetadataProviderConfiguration::class),
            $configuration
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
        if (!$object instanceof MetadataProviderConfiguration) {
            return;
        }

        $object->setMetronPassword($this->encryption->encrypt($object->getMetronPassword()));
        $object->setComicVineApiKey($this->encryption->encrypt($object->getComicVineApiKey()));
    }

    private function decrypt(object $object): void
    {
        if (!$object instanceof MetadataProviderConfiguration) {
            return;
        }

        $object->setMetronPassword($this->encryption->decrypt($object->getMetronPassword()));
        $object->setComicVineApiKey($this->encryption->decrypt($object->getComicVineApiKey()));
    }
}
