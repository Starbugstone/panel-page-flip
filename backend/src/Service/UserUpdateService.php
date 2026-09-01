<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Http\ConstraintViolationErrors;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Applies and audits one accepted account update. */
final class UserUpdateService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly PasswordValidator $passwordValidator,
        private readonly AdminAuditService $adminAudit,
        private readonly SecurityAuditLogger $securityAudit,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws UserUpdateRejected
     */
    public function update(User $actor, User $target, array $data): void
    {
        $name = $this->name($data);
        $roles = $this->roles($actor, $target, $data);
        $metadataApiEnabled = $this->metadataApiEnabled($actor, $data);
        $password = $this->password($data);

        $before = [
            'name' => $target->getName(),
            'roles' => $target->getRoles(),
            'metadataApiEnabled' => $target->isMetadataApiEnabled(),
            'password' => $target->getPassword(),
        ];

        if ($name !== null) {
            $target->setName($name);
        }
        if ($roles !== null) {
            $target->setRoles($roles);
        }
        if ($metadataApiEnabled !== null) {
            $target->setMetadataApiEnabled($metadataApiEnabled);
        }

        $passwordChanged = $password !== null;
        if ($passwordChanged) {
            $target->setPassword($this->passwordHasher->hashPassword($target, $password));
        }

        $violations = $this->validator->validate($target);
        if (count($violations) > 0) {
            $target
                ->setName($before['name'])
                ->setRoles($before['roles'])
                ->setMetadataApiEnabled($before['metadataApiEnabled'])
                ->setPassword($before['password']);

            throw new UserUpdateRejected(
                'Validation failed',
                Response::HTTP_BAD_REQUEST,
                ConstraintViolationErrors::from($violations),
            );
        }

        $afterRoles = $target->getRoles();
        $this->recordAdminChanges(
            $actor,
            $target,
            $before['roles'],
            $afterRoles,
            $before['metadataApiEnabled'],
            $metadataApiEnabled,
        );
        $this->entityManager->flush();
        $this->recordSecurityChanges($actor, $target, $before['roles'], $afterRoles, $passwordChanged);
    }

    /** @param array<array-key, mixed> $data */
    private function name(array $data): ?string
    {
        if (!isset($data['name'])) {
            return null;
        }
        if (!is_string($data['name'])) {
            throw new UserUpdateRejected(
                'Validation failed',
                Response::HTTP_BAD_REQUEST,
                ['name' => ['Name must be a string.']],
            );
        }

        return $data['name'];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>|null
     */
    private function roles(User $actor, User $target, array $data): ?array
    {
        if (!isset($data['roles']) || !$actor->isAdmin()) {
            return null;
        }

        $roles = $data['roles'];
        if (!is_array($roles)
            || !array_is_list($roles)
            || array_filter($roles, static fn (mixed $role): bool => !is_string($role)) !== []
        ) {
            throw new UserUpdateRejected('Roles must be an array of role names.', Response::HTTP_BAD_REQUEST);
        }
        if (!in_array('ROLE_USER', $roles, true)) {
            $roles[] = 'ROLE_USER';
        }
        $roles = array_values(array_unique($roles));

        if ($target->getId() === $actor->getId() && !in_array('ROLE_ADMIN', $roles, true)) {
            throw new UserUpdateRejected('You cannot remove your own admin role', Response::HTTP_FORBIDDEN);
        }

        if ($target->isAdmin() && !in_array('ROLE_ADMIN', $roles, true)) {
            $remainingAdmins = $this->entityManager->getRepository(User::class)->countAdminsExcluding($target);
            if ($remainingAdmins === 0) {
                $this->securityAudit->suspicious(
                    SecurityAuditLogger::LAST_ADMIN_PROTECTED,
                    'user:'.$actor->getId(),
                    [
                        'actor_user_id' => $actor->getId(),
                        'target_user_id' => $target->getId(),
                        'target_type' => 'user',
                    ],
                    3,
                );

                throw new UserUpdateRejected('There must be at least one admin', Response::HTTP_CONFLICT);
            }
        }

        return $roles;
    }

    /** @param array<array-key, mixed> $data */
    private function metadataApiEnabled(User $actor, array $data): ?bool
    {
        if (!array_key_exists('metadataApiEnabled', $data) || !$actor->isAdmin()) {
            return null;
        }
        if (!is_bool($data['metadataApiEnabled'])) {
            $message = 'External metadata API access must be true or false.';

            throw new UserUpdateRejected(
                $message,
                Response::HTTP_BAD_REQUEST,
                ['metadataApiEnabled' => [$message]],
            );
        }

        return $data['metadataApiEnabled'];
    }

    /** @param array<array-key, mixed> $data */
    private function password(array $data): ?string
    {
        if (!isset($data['password']) || $data['password'] === '') {
            return null;
        }
        if (!is_string($data['password'])) {
            throw new UserUpdateRejected(
                'Validation failed',
                Response::HTTP_BAD_REQUEST,
                ['password' => ['Password must be a string.']],
            );
        }

        $errors = $this->passwordValidator->validate($data['password']);
        if ($errors !== []) {
            throw new UserUpdateRejected(
                'Password does not meet policy requirements.',
                Response::HTTP_BAD_REQUEST,
                ['password' => $errors],
            );
        }

        return $data['password'];
    }

    /**
     * @param list<string> $beforeRoles
     * @param list<string> $afterRoles
     */
    private function recordAdminChanges(
        User $actor,
        User $target,
        array $beforeRoles,
        array $afterRoles,
        bool $beforeMetadataApiEnabled,
        ?bool $metadataApiEnabled,
    ): void {
        if (!$actor->isAdmin()) {
            return;
        }

        if ($metadataApiEnabled !== null && $beforeMetadataApiEnabled !== $target->isMetadataApiEnabled()) {
            $this->adminAudit->log(
                $actor,
                $metadataApiEnabled ? 'user_metadata_api_enabled' : 'user_metadata_api_disabled',
                'user',
                $target->getId(),
                ['target_user_id' => $target->getId()],
            );
        }

        if ($beforeRoles !== $afterRoles || $actor->getId() !== $target->getId()) {
            $this->adminAudit->log($actor, 'user_update', 'user', $target->getId(), [
                'email' => $target->getEmail(),
                'rolesBefore' => $beforeRoles,
                'rolesAfter' => $afterRoles,
            ]);
        }
    }

    /**
     * @param list<string> $beforeRoles
     * @param list<string> $afterRoles
     */
    private function recordSecurityChanges(
        User $actor,
        User $target,
        array $beforeRoles,
        array $afterRoles,
        bool $passwordChanged,
    ): void {
        if ($passwordChanged) {
            $this->securityAudit->audit(SecurityAuditLogger::USER_PASSWORD_CHANGED, [
                'actor_user_id' => $actor->getId(),
                'target_user_id' => $target->getId(),
                'target_type' => 'user',
                'changed_by_admin' => $actor->getId() !== $target->getId(),
            ]);
        }

        if ($beforeRoles === $afterRoles) {
            return;
        }

        $this->securityAudit->audit(SecurityAuditLogger::USER_ROLES_CHANGED, [
            'actor_user_id' => $actor->getId(),
            'target_user_id' => $target->getId(),
            'target_type' => 'user',
            'roles_before' => $beforeRoles,
            'roles_after' => $afterRoles,
        ]);

        $wasAdmin = in_array('ROLE_ADMIN', $beforeRoles, true);
        $isAdmin = in_array('ROLE_ADMIN', $afterRoles, true);
        if ($wasAdmin !== $isAdmin) {
            $this->securityAudit->critical(SecurityAuditLogger::ADMIN_ROLE_CHANGED, [
                'actor_user_id' => $actor->getId(),
                'target_user_id' => $target->getId(),
                'target_type' => 'user',
                'change' => $isAdmin ? 'granted' : 'removed',
                'roles_before' => $beforeRoles,
                'roles_after' => $afterRoles,
            ], SecurityAuditLogger::RESULT_SUCCESS, 'user:'.$target->getId());
        }
    }
}
