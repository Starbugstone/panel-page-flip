<?php

namespace App\Security\Voter;

use App\Entity\Comic;
use App\Entity\User;
use App\Repository\ComicShareRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The single place that decides who may do what with a comic.
 *
 * Every endpoint that serves any part of a comic — metadata, cover, page,
 * archive — asks this the same question, so a recipient's access cannot be
 * correct in one place and missing in another.
 *
 * @extends Voter<string, Comic>
 */
final class ComicVoter extends Voter
{
    /** Read the comic: its metadata, cover, pages and reading position. */
    public const VIEW = 'COMIC_VIEW';

    /** Change the comic's metadata or tags. */
    public const EDIT = 'COMIC_EDIT';

    /** Delete the comic and its files, for everyone. */
    public const DELETE = 'COMIC_DELETE';

    /** Invite somebody else, or manage who currently has access. */
    public const SHARE = 'COMIC_SHARE';

    /**
     * Be told this comic exists at all.
     *
     * The weakest right here, and the only one that is not about doing
     * something: it decides whether a refusal may say "you may not" rather than
     * "there is no such comic". Everyone with any standing has it — the owner
     * even while the comic is quarantined, an administrator, and any recipient
     * holding a share of any status, including one that grants no reading.
     *
     * A stranger does not, which is the point: without it, walking the id space
     * would map out other people's libraries by which ids answered differently.
     */
    public const KNOW = 'COMIC_KNOW';

    public function __construct(private readonly ComicShareRepository $shareRepository)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Comic
            && in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::SHARE, self::KNOW], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Comic) {
            return false;
        }

        $isOwner = $subject->getOwner()?->getId() === $user->getId();
        $isAdmin = $user->isAdmin();

        return match ($attribute) {
            // Sharing is the owner's alone. An admin can moderate a comic but
            // handing out access on somebody else's behalf is not moderation.
            self::SHARE => $isOwner
                && !$user->isSharingRestricted()
                && !$subject->isSharingRestricted()
                && !$subject->isQuarantined(),

            // Administration keeps the reach it already had over the library.
            self::EDIT, self::DELETE => $isOwner || $isAdmin,

            // A recipient reads through the owner's single copy; they never own
            // anything, so this is the only attribute a share can satisfy.
            self::VIEW => $isAdmin
                || ($isOwner && !$subject->isQuarantined())
                || (!$subject->isSharingRestricted() && !$subject->isQuarantined() && $this->hasAcceptedShare($user, $subject)),

            // Standing, not permission. Note what it does *not* consult:
            // quarantine, sharing restrictions, share status and the 18+ gate
            // all withdraw reading from somebody who still knows the comic is
            // there, and pretending otherwise to them would be a worse answer
            // than the refusal they have earned.
            self::KNOW => $isOwner
                || $isAdmin
                || $this->shareRepository->hasAnyShareFor($user, $subject),

            default => false,
        };
    }

    private function hasAcceptedShare(User $user, Comic $comic): bool
    {
        // Hiding a comic from your own collection does not give up access —
        // findAccessFor deliberately ignores recipientRemovedAt — so a link
        // straight to the reader keeps working until the owner revokes it.
        //
        // It does apply the 18+ gate, which is why that gate holds for covers,
        // pages and archives and not only for the screen that asks the question:
        // an accepted share on a comic marked explicit answers no here until
        // that recipient has declared their age on it.
        return $this->shareRepository->findAccessFor($user, $comic) !== null;
    }
}
