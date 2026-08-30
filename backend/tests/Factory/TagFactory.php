<?php

namespace App\Tests\Factory;

use App\Entity\Tag;
use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Tag>
 */
final class TagFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Tag::class;
    }

    protected function defaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
            'creator' => UserFactory::new(),
        ];
    }

    public function createdBy(User $user): self
    {
        return $this->with(['creator' => $user]);
    }
}
