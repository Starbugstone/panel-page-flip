<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\ShareClaimCodeRepository;
use App\Service\SharingCodeFormat;
use App\Service\UsernameGenerator;
use App\Service\UsernamePolicy;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Every account gets a username and a `U-` code, whoever created it.
 *
 * The invariant is "every user has both", and there are six ways to make a
 * user: registration, the admin panel, two console commands, the sample-data
 * generator and the test factory. Six copies of "and don't forget to give it a
 * username" is five copies too many, and the one that gets forgotten produces
 * an account nobody can share with — discovered much later, by somebody trying
 * to share with it.
 *
 * So it happens once, here, at the point the row is created. Registration still
 * assigns the username the person actually chose; this only fills in what is
 * still blank, which for every other path is both fields.
 *
 * It is not what makes them unique. The unique indexes are, and they remain the
 * authority when two rows race — this only avoids handing out a name that was
 * already visibly taken.
 */
#[AsEntityListener(event: Events::prePersist, entity: User::class)]
final class UserIdentityListener
{
    /**
     * Names and codes handed out in this process but not yet committed.
     *
     * A query cannot see a sibling created in the same flush, so a request that
     * creates several accounts at once — the sample-data generator does exactly
     * that — could otherwise be given the same name twice and fail on the
     * insert. Cheap to keep, and it only has to survive the flush.
     *
     * @var array<string, true>
     */
    private array $pendingUsernames = [];

    /** @var array<string, true> */
    private array $pendingCodes = [];

    public function __construct(
        private readonly UsernameGenerator $generator,
        private readonly ShareClaimCodeRepository $contentCodes,
    ) {
    }

    public function prePersist(User $user, PrePersistEventArgs $event): void
    {
        $manager = $event->getObjectManager();
        $repository = $manager->getRepository(User::class);

        if ($user->getUsername() === '') {
            $user->setUsername($this->allocate(
                fn (int $attempt): string => $this->generator->generate($attempt < 5 ? 4 : 8),
                fn (string $candidate): bool => isset($this->pendingUsernames[UsernamePolicy::canonicalise($candidate)])
                    || $repository->findOneBy(['usernameCanonical' => UsernamePolicy::canonicalise($candidate)]) !== null
            ));
            $this->pendingUsernames[$user->getUsernameCanonical()] = true;
        }

        if ($user->getUserCode() === '') {
            $user->assignUserCode($this->allocate(
                static fn (): string => SharingCodeFormat::generate(),
                // Content codes too, so one visible token never means two
                // things at once — the property that makes a code safe to read
                // aloud, over and above the prefix that tells the kinds apart.
                fn (string $candidate): bool => isset($this->pendingCodes[$candidate])
                    || $repository->findOneBy(['userCode' => $candidate]) !== null
                    || $this->contentCodes->existsForToken($candidate)
            ));
            $this->pendingCodes[$user->getUserCode()] = true;
        }
    }

    /**
     * Draw candidates until one is free, then give up and use the last.
     *
     * Giving up rather than throwing: the fallback is a valid value that the
     * unique index will refuse if it really is taken, and an exception here
     * would abort a registration over a collision the database is about to
     * adjudicate anyway.
     *
     * @param callable(int): string  $draw
     * @param callable(string): bool $taken
     */
    private function allocate(callable $draw, callable $taken): string
    {
        $candidate = '';

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $candidate = $draw($attempt);

            if (!$taken($candidate)) {
                return $candidate;
            }
        }

        return $candidate;
    }
}
