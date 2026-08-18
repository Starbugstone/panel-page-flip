<?php

namespace App\Tests\Factory;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\ModelFactory;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\RepositoryProxy;

/**
 * @extends ModelFactory<User>
 *
 * @method        User|Proxy create(array|callable $attributes = [])
 * @method static User|Proxy createOne(array $attributes = [])
 * @method static User|Proxy find(object|array|mixed $criteria)
 * @method static User|Proxy findOrCreate(array $attributes)
 * @method static User|Proxy first(string $sortedField = 'id')
 * @method static User|Proxy last(string $sortedField = 'id')
 * @method static User|Proxy random(array $attributes = [])
 * @method static User|Proxy randomOrCreate(array $attributes = [])
 * @method static User[]|Proxy[] all()
 * @method static User[]|Proxy[] createMany(int $number, array|callable $attributes = [])
 * @method static User[]|Proxy[] createSequence(iterable|callable $sequence)
 * @method static User[]|Proxy[] findBy(array $attributes)
 * @method static User[]|Proxy[] randomRange(int $min, int $max, array $attributes = [])
 * @method static User[]|Proxy[] randomSet(int $number, array $attributes = [])
 * @method static RepositoryProxy repository()
 */
final class UserFactory extends ModelFactory
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

    protected static function getClass(): string
    {
        return User::class;
    }

    protected function getDefaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'name' => self::faker()->name(),
            'roles' => ['ROLE_USER'],
            'password' => self::PASSWORD,
            'isEmailVerified' => true,
        ];
    }

    protected function initialize(): self
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
        return $this->addState(['roles' => ['ROLE_ADMIN', 'ROLE_USER']]);
    }

    public function unverified(): self
    {
        return $this->addState(['isEmailVerified' => false]);
    }
}
