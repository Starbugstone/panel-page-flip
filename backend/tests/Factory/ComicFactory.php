<?php

namespace App\Tests\Factory;

use App\Entity\Comic;
use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Comic>
 */
final class ComicFactory extends PersistentProxyObjectFactory
{
    public static function class(): string
    {
        return Comic::class;
    }

    protected function defaults(): array
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
            // Never randomised. An age classification is the owner's deliberate
            // statement, and a test comic that is sometimes 18+ would make the
            // gate's tests pass for the wrong reason.
            'explicitContent' => false,
            'owner' => UserFactory::new(),
        ];
    }

    public function ownedBy(User $user): self
    {
        return $this->with(['owner' => $user]);
    }

    public function explicit(): self
    {
        return $this->with(['explicitContent' => true]);
    }
}
