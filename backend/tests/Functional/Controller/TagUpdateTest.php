<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class TagUpdateTest extends AbstractApiTestCase
{
    public function testOwnerCanRenameTheirTag(): void
    {
        $user = $this->createAndLoginUser();
        $tag = TagFactory::new()->createdBy($user)->create(['name' => 'Old']);

        $payload = $this->putJson('/api/tags/'.$tag->getId(), ['name' => 'Renamed']);

        self::assertResponseIsSuccessful();
        self::assertSame('Tag updated successfully', $payload['message']);
        self::assertSame('Renamed', $payload['tag']['name']);
    }

    public function testUnknownTagIsNotFound(): void
    {
        $this->createAndLoginUser();

        $payload = $this->putJson('/api/tags/999999', ['name' => 'Nope']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Tag not found', $payload['message']);
    }

    public function testOrdinaryUsersCannotRenameSomeoneElsestag(): void
    {
        $this->createAndLoginUser();
        $other = UserFactory::createOne();
        $tag = TagFactory::new()->createdBy($other)->create(['name' => 'Theirs']);

        $payload = $this->putJson('/api/tags/'.$tag->getId(), ['name' => 'Stolen']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('You are not authorized to update this tag', $payload['message']);
    }

    public function testOrdinaryUsersCannotRenameAGlobalTag(): void
    {
        $this->createAndLoginUser();
        $tag = TagFactory::createOne(['name' => 'Marvel', 'creator' => null, 'isGlobal' => true]);

        $payload = $this->putJson('/api/tags/'.$tag->getId(), ['name' => 'DC']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Only administrators can update global tags', $payload['message']);
    }

    public function testRenameConflictReturnsTheExistingTag(): void
    {
        $user = $this->createAndLoginUser();
        TagFactory::new()->createdBy($user)->create(['name' => 'Taken']);
        $tag = TagFactory::new()->createdBy($user)->create(['name' => 'Free']);

        $payload = $this->putJson('/api/tags/'.$tag->getId(), ['name' => 'Taken']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Tag name already exists', $payload['message']);
        self::assertSame('Taken', $payload['tag']['name']);
    }

    public function testEmptyNameIsRejected(): void
    {
        $user = $this->createAndLoginUser();
        $tag = TagFactory::new()->createdBy($user)->create(['name' => 'Keep']);

        $payload = $this->putJson('/api/tags/'.$tag->getId(), ['name' => '   ']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Tag name is required', $payload['message']);
    }
}
