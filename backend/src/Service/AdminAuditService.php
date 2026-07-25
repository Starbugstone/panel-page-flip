<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class AdminAuditService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function log(User $adminUser, string $action, string $targetType, ?int $targetId = null, ?array $payload = null): void
    {
        $entry = (new AdminAuditLog())
            ->setAdminUser($adminUser)
            ->setAction($action)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setPayload($payload);

        $this->entityManager->persist($entry);
    }
}
