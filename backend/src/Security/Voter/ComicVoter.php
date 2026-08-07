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

    public function __construct(private readonly ComicShareRepository $shareRepository)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Comic
            && in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::SHARE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$subject instanceof Comic) {
            return false;
        }

        $isOwner = $subject->getOwner()?->getId() === $user->getId();
        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        return match ($attribute) {
            // Sharing is the owner's alone. An admin can moderate a comic but
            // handing out access on somebody else's behalf is not moderation.
            self::SHARE => $isOwner,

            // Administration keeps the reach it already had over the library.
            self::EDIT, self::DELETE => $isOwner || $isAdmin,

            // A recipient reads through the owner's single copy; they never own
            // anything, so this is the only attribute a share can satisfy.
            self::VIEW => $isOwner || $isAdmin || $this->hasAcceptedShare($user, $subject),

            default => false,
        };
    }

    private function hasAcceptedShare(User $user, Comic $comic): bool
    {
        // Hiding a comic from your own collection does not give up access —
        // findAccessFor deliberately ignores recipientRemovedAt — so a link
        // straight to the reader keeps working until the owner revokes it.
        return $this->shareRepository->findAccessFor($user, $comic) !== null;
    }
}
