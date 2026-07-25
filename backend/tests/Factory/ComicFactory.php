<?php

namespace App\Tests\Factory;

use App\Entity\Comic;
use App\Entity\User;
use Zenstruck\Foundry\ModelFactory;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\RepositoryProxy;

/**
 * @extends ModelFactory<Comic>
 *
 * @method        Comic|Proxy create(array|callable $attributes = [])
 * @method static Comic|Proxy createOne(array $attributes = [])
 * @method static Comic|Proxy find(object|array|mixed $criteria)
 * @method static Comic|Proxy findOrCreate(array $attributes)
 * @method static Comic[]|Proxy[] all()
 * @method static Comic[]|Proxy[] createMany(int $number, array|callable $attributes = [])
 * @method static Comic[]|Proxy[] findBy(array $attributes)
 * @method static RepositoryProxy repository()
 */
final class ComicFactory extends ModelFactory
{
    protected static function getClass(): string
    {
        return Comic::class;
    }

    protected function getDefaults(): array
    {
        $title = self::faker()->sentence(3);

        return [
            'title' => $title,
            'filePath' => 'comics/test/' . self::faker()->slug() . '.cbz',
            'pageCount' => self::faker()->numberBetween(10, 80),
            'fileSize' => self::faker()->numberBetween(100_000, 50_000_000),
            'author' => self::faker()->name(),
            'publisher' => self::faker()->company(),
            'description' => self::faker()->sentence(),
            'owner' => UserFactory::new(),
        ];
    }

    public function ownedBy(User $user): self
    {
        return $this->addState(['owner' => $user]);
    }
}
