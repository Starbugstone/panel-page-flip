<?php

namespace App\Tests\Factory;

use App\Entity\Tag;
use App\Entity\User;
use Zenstruck\Foundry\ModelFactory;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\RepositoryProxy;

/**
 * @extends ModelFactory<Tag>
 *
 * @method        Tag|Proxy create(array|callable $attributes = [])
 * @method static Tag|Proxy createOne(array $attributes = [])
 * @method static Tag|Proxy find(object|array|mixed $criteria)
 * @method static Tag|Proxy findOrCreate(array $attributes)
 * @method static Tag[]|Proxy[] all()
 * @method static Tag[]|Proxy[] createMany(int $number, array|callable $attributes = [])
 * @method static Tag[]|Proxy[] findBy(array $attributes)
 * @method static RepositoryProxy repository()
 */
final class TagFactory extends ModelFactory
{
    protected static function getClass(): string
    {
        return Tag::class;
    }

    protected function getDefaults(): array
    {
        return [
            'name' => self::faker()->unique()->word(),
            'creator' => UserFactory::new(),
        ];
    }

    public function createdBy(User $user): self
    {
        return $this->addState(['creator' => $user]);
    }
}
