<?php

namespace App\Tests\Factory;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    /**
     * The password every factory-made account has.
     *
     * Named rather than repeated, so a test that has to send the plaintext to
     * an endpoint — registering, or an administrator creating an account — says
     * which password it means instead of restating one that has to stay in
     * step with this file.
     */
    public const PASSWORD = 'P@ssw0rd!Strong';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
        parent::__construct();
    }

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'name' => self::faker()->name(),
            'roles' => ['ROLE_USER'],
            'password' => self::PASSWORD,
            'isEmailVerified' => true,
        ];
    }

    protected function initialize(): static
    {
        return $this
            ->afterInstantiate(function (User $user): void {
                if ($user->getPassword() && !str_starts_with((string) $user->getPassword(), '$')) {
                    $user->setPassword($this->passwordHasher->hashPassword($user, $user->getPassword()));
                }
            });
    }

    public function admin(): self
    {
        return $this->with(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
    }

    public function unverified(): self
    {
        return $this->with(['isEmailVerified' => false]);
    }
}
