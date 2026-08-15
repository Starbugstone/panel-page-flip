<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\MetadataProviderConfiguration;
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
final class MetadataProviderSecretsSubscriber
{
    /** @var \WeakMap<MetadataProviderConfiguration, array{metron: ?string, comicVine: ?string}> */
    private \WeakMap $logicalSnapshots;

    public function __construct(private readonly AppDataEncryptionService $encryption)
    {
        $this->logicalSnapshots = new \WeakMap(); // @phpstan-ignore assign.propertyType (WeakMap TValue is invariant)
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $configuration = $args->getObject();
        if ($configuration instanceof MetadataProviderConfiguration) {
            $this->decryptAndSynchronize($configuration, $args->getObjectManager());
        }
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $configuration = $args->getObject();
        if (!$configuration instanceof MetadataProviderConfiguration) {
            return;
        }

        $configuration->setMetronPassword($this->encryption->encrypt($configuration->getMetronPassword()));
        $configuration->setComicVineApiKey($this->encryption->encrypt($configuration->getComicVineApiKey()));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $configuration = $args->getObject();
        if (!$configuration instanceof MetadataProviderConfiguration) {
            return;
        }

        $snapshot = $this->logicalSnapshots[$configuration] ?? [
            'metron' => $configuration->getMetronPassword(),
            'comicVine' => $configuration->getComicVineApiKey(),
        ];
        $metronChanged = $configuration->getMetronPassword() !== $snapshot['metron'];
        $comicVineChanged = $configuration->getComicVineApiKey() !== $snapshot['comicVine'];

        if (!$metronChanged && !$comicVineChanged) {
            return;
        }

        if ($metronChanged) {
            $configuration->setMetronPassword($this->encryption->encrypt($configuration->getMetronPassword()));
        }
        if ($comicVineChanged) {
            $configuration->setComicVineApiKey($this->encryption->encrypt($configuration->getComicVineApiKey()));
        }

        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata(MetadataProviderConfiguration::class),
            $configuration
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $configuration = $args->getObject();
        if ($configuration instanceof MetadataProviderConfiguration) {
            $this->decryptAndSynchronize($configuration, $args->getObjectManager());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $configuration = $args->getObject();
        if ($configuration instanceof MetadataProviderConfiguration) {
            $this->decryptAndSynchronize($configuration, $args->getObjectManager());
        }
    }

    private function decryptAndSynchronize(
        MetadataProviderConfiguration $configuration,
        EntityManagerInterface $entityManager
    ): void {
        $oid = spl_object_id($configuration);
        $metron = $this->encryption->decrypt($configuration->getMetronPassword());
        $comicVine = $this->encryption->decrypt($configuration->getComicVineApiKey());

        $configuration->setMetronPassword($metron);
        $configuration->setComicVineApiKey($comicVine);
        $snapshots = $this->logicalSnapshots;
        $snapshots[$configuration] = ['metron' => $metron, 'comicVine' => $comicVine];

        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->setOriginalEntityProperty($oid, 'metronPassword', $metron);
        $unitOfWork->setOriginalEntityProperty($oid, 'comicVineApiKey', $comicVine);
    }
}
