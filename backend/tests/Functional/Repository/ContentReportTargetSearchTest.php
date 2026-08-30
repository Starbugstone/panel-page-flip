<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Repository\ComicRepository;
use App\Repository\UserRepository;
use App\Tests\Factory\ComicFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\AbstractApiTestCase;

final class ContentReportTargetSearchTest extends AbstractApiTestCase
{
    public function testWildcardAndEscapeCharactersAreSearchedLiterally(): void
    {
        $users = [
            ['query' => '100%', 'literal' => UserFactory::createOne(['name' => 'Owner 100% Literal']), 'decoy' => UserFactory::createOne(['name' => 'Owner 100X Literal'])],
            ['query' => 'owner_', 'literal' => UserFactory::createOne(['name' => 'owner_literal']), 'decoy' => UserFactory::createOne(['name' => 'ownerXliteral'])],
            ['query' => 'path \\', 'literal' => UserFactory::createOne(['name' => 'path \\ literal']), 'decoy' => UserFactory::createOne(['name' => 'path X literal'])],
        ];
        $comics = [
            ['query' => '100%', 'literal' => ComicFactory::createOne(['title' => 'Edition 100% Literal']), 'decoy' => ComicFactory::createOne(['title' => 'Edition 100X Literal'])],
            ['query' => 'issue_', 'literal' => ComicFactory::createOne(['title' => 'issue_literal']), 'decoy' => ComicFactory::createOne(['title' => 'issueXliteral'])],
            ['query' => 'path \\', 'literal' => ComicFactory::createOne(['title' => 'path \\ literal']), 'decoy' => ComicFactory::createOne(['title' => 'path X literal'])],
        ];

        foreach ($users as $case) {
            $ids = array_map(static fn ($user): ?int => $user->getId(), $this->users()->searchForContentReport($case['query']));
            self::assertContains($case['literal']->getId(), $ids);
            self::assertNotContains($case['decoy']->getId(), $ids);
        }
        foreach ($comics as $case) {
            $ids = array_map(static fn ($comic): ?int => $comic->getId(), $this->comics()->searchForContentReport($case['query']));
            self::assertContains($case['literal']->getId(), $ids);
            self::assertNotContains($case['decoy']->getId(), $ids);
        }
    }

    private function users(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }

    private function comics(): ComicRepository
    {
        return static::getContainer()->get(ComicRepository::class);
    }
}
