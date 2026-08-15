<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\MetadataProviderConfiguration;
use App\Entity\UserMetadataCredential;
use App\Service\AppDataEncryptionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps provider credentials encrypted at rest while exposing plaintext to the
 * application.
 *
 * Both the installation-owned provider configuration and each user's personal
 * provider credentials use the same lifecycle. After decryption, Doctrine's
 * original-data snapshot is synchronised to the logical plaintext values. This
 * is important: without it, an unrelated later flush can treat the decrypted
 * value as a change and write stale credentials back over a newer database
 * value.
 */
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
final class MetadataProviderSecretsSubscriber
{
    /**
     * @var \WeakMap<MetadataProviderConfiguration|UserMetadataCredential, array{metron: ?string, comicVine: ?string}>
     */
    private \WeakMap $logicalSnapshots;

    public function __construct(private readonly AppDataEncryptionService $encryption)
    {
        // WeakMap TValue is invariant, so PHPStan cannot infer the documented
        // generic value type from the empty constructor.
        $this->logicalSnapshots = new \WeakMap(); // @phpstan-ignore assign.propertyType
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->holdsSecrets($entity)) {
            $this->decryptAndSynchronize($entity, $args->getObjectManager());
        }
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->holdsSecrets($entity)) {
            return;
        }

        $this->encryptAll($entity);
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$this->holdsSecrets($entity)) {
            return;
        }

        $snapshot = $this->logicalSnapshots[$entity] ?? [
            'metron' => $entity->getMetronToken(),
            'comicVine' => $entity->getComicVineApiKey(),
        ];
        $metronChanged = $entity->getMetronToken() !== $snapshot['metron'];
        $comicVineChanged = $entity->getComicVineApiKey() !== $snapshot['comicVine'];

        if (!$metronChanged && !$comicVineChanged) {
            return;
        }

        if ($metronChanged) {
            $entity->setMetronToken($this->encryption->encrypt($entity->getMetronToken()));
        }
        if ($comicVineChanged) {
            $entity->setComicVineApiKey($this->encryption->encrypt($entity->getComicVineApiKey()));
        }

        $entityManager = $args->getObjectManager();
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata($entity::class),
            $entity
        );
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->holdsSecrets($entity)) {
            $this->decryptAndSynchronize($entity, $args->getObjectManager());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($this->holdsSecrets($entity)) {
            $this->decryptAndSynchronize($entity, $args->getObjectManager());
        }
    }

    private function encryptAll(MetadataProviderConfiguration|UserMetadataCredential $entity): void
    {
        $entity->setMetronToken($this->encryption->encrypt($entity->getMetronToken()));
        $entity->setComicVineApiKey($this->encryption->encrypt($entity->getComicVineApiKey()));
    }

    private function decryptAndSynchronize(
        MetadataProviderConfiguration|UserMetadataCredential $entity,
        EntityManagerInterface $entityManager
    ): void {
        $oid = spl_object_id($entity);
        $metron = $this->encryption->decrypt($entity->getMetronToken());
        $comicVine = $this->encryption->decrypt($entity->getComicVineApiKey());

        $entity->setMetronToken($metron);
        $entity->setComicVineApiKey($comicVine);
        $snapshots = $this->logicalSnapshots;
        $snapshots[$entity] = ['metron' => $metron, 'comicVine' => $comicVine];

        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->setOriginalEntityProperty($oid, 'metronToken', $metron);
        $unitOfWork->setOriginalEntityProperty($oid, 'comicVineApiKey', $comicVine);
    }

    private function holdsSecrets(object $entity): bool
    {
        return $entity instanceof MetadataProviderConfiguration || $entity instanceof UserMetadataCredential;
    }
}
