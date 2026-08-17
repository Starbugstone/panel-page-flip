<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Comic;
use App\Entity\User;
use App\Repository\ComicRepository;
use App\Security\Voter\ComicVoter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Load a comic and settle whether the caller may have it, in one step.
 *
 * Every endpoint that takes a comic id used to spell this out itself: find it,
 * answer 404 when it is missing, ask {@see ComicVoter}, answer 403 when it says
 * no. Twenty repetitions of four lines is not the problem — the problem is that
 * they did not agree. Some answered 403 and some 404 for the very same
 * "somebody else's comic", which made the difference between the two a working
 * existence oracle for any id an attacker cared to try.
 *
 * Joining the lookup to the authorization is what makes that unrepresentable:
 * there is no point in between where a caller holds a comic it has not been
 * cleared for, and no second answer for it to give.
 */
final class ComicAccess
{
    public function __construct(
        private readonly ComicRepository $comics,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly LoggerInterface $logger,
        private readonly Security $security
    ) {
    }

    /**
     * The comic, if this caller may do that to it.
     *
     * Refusals come in two flavours, and which one is used is itself a
     * disclosure decision:
     *
     * - {@see ComicNotAccessibleException} (404) for somebody with no standing
     *   at all. Identical to the answer for an id that was never issued, so
     *   working through the id space reveals nothing.
     * - {@see ComicForbiddenException} (403) for somebody who already knows the
     *   comic exists but may not do this to it — a recipient trying to edit or
     *   download what was shared with them. Hiding it behind "no such comic"
     *   would only confuse; the honest refusal tells them nothing new.
     *
     * {@see ComicVoter::KNOW} draws that line rather than
     * {@see ComicVoter::VIEW}, because reading is withdrawn from plenty of
     * people who are perfectly aware of the comic: an owner whose comic is
     * quarantined, a recipient who has not answered the 18+ gate, one whose
     * access was revoked. Answering all of them with "no such comic" would be a
     * lie told to protect nobody.
     *
     * @param ComicVoter::VIEW|ComicVoter::EDIT|ComicVoter::DELETE|ComicVoter::SHARE $attribute
     *
     * @throws ComicNotAccessibleException when this caller has no standing at all
     * @throws ComicForbiddenException     when they know of it but may not do this
     */
    public function requireComic(int $id, string $attribute): Comic
    {
        $comic = $this->comics->find($id);
        if ($comic === null) {
            throw new ComicNotAccessibleException();
        }

        if ($this->authorization->isGranted($attribute, $comic)) {
            return $comic;
        }

        if ($this->authorization->isGranted(ComicVoter::KNOW, $comic)) {
            throw new ComicForbiddenException();
        }

        // The refusal that answers 404 is the one the response will not record,
        // because to the outside it is indistinguishable from an id that never
        // existed. It is worth a line here: reaching a real comic with no
        // standing at all is either probing or a misconfiguration, and the 404
        // gives the access-denied subscriber nothing to count.
        $user = $this->security->getUser();
        $this->logger->warning('Refused access to a comic the caller has no claim on.', [
            'comic_id' => $comic->getId(),
            'attribute' => $attribute,
            'actor_user_id' => $user instanceof User ? $user->getId() : null,
        ]);

        throw new ComicNotAccessibleException();
    }
}
